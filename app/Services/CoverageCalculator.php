<?php

namespace App\Services;

use App\Enums\CoverageLevel;
use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Support\Coverage;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CoverageCalculator
{
    /** Seuls ces motifs sont de la vraie consommation : ni pertes, ni casses, ni vols, ni péremptions. */
    private const CONSUMPTION_REASONS = [MovementReason::Sale, MovementReason::InternalUse];

    public function forProduct(Product $product): Coverage
    {
        return $this->forProducts([$product])->get($product->id);
    }

    /**
     * Calcule la couverture de plusieurs produits avec un nombre constant de requêtes.
     *
     * @param  iterable<Product>  $products
     * @return Collection<int, Coverage> indexée par identifiant de produit
     */
    public function forProducts(iterable $products): Collection
    {
        $products = collect($products)->values();

        if ($products->isEmpty()) {
            return collect();
        }

        $window = max(1, (int) config('stockflow.coverage.window_days'));
        $windowStart = now()->subDays($window);

        $consumed = [];
        $firstAt = [];
        $expiredUnits = [];

        foreach ($products->chunk(1000) as $chunk) {
            $ids = $chunk->pluck('id')->all();
            $trackedIds = $chunk->where('tracks_lots', true)->pluck('id')->all();

            $rows = StockMovement::query()
                ->whereIn('product_id', $ids)
                ->where('type', MovementType::Exit->value)
                ->where('status', MovementStatus::Applied->value)
                ->whereIn('reason_code', array_map(fn (MovementReason $reason) => $reason->value, self::CONSUMPTION_REASONS))
                ->where('occurred_at', '>=', $windowStart)
                // Une vente annulée par une correction n'est plus une consommation.
                ->whereDoesntHave('correction')
                ->selectRaw('product_id, SUM(quantity) as consumed')
                ->groupBy('product_id')
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $consumed[$row->product_id] = (int) $row->consumed;
            }

            $rows = StockMovement::query()
                ->whereIn('product_id', $ids)
                ->where('status', MovementStatus::Applied->value)
                ->selectRaw('product_id, MIN(occurred_at) as first_at')
                ->groupBy('product_id')
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $firstAt[$row->product_id] = $row->first_at;
            }

            if ($trackedIds !== []) {
                $rows = StockLot::query()
                    ->whereIn('product_id', $trackedIds)
                    ->where('quantity_remaining', '>', 0)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', today()->toDateString())
                    ->selectRaw('product_id, SUM(quantity_remaining) as expired_units')
                    ->groupBy('product_id')
                    ->toBase()
                    ->get();

                foreach ($rows as $row) {
                    $expiredUnits[$row->product_id] = (int) $row->expired_units;
                }
            }
        }

        return $products->mapWithKeys(fn (Product $product) => [
            $product->id => $this->compute(
                $product,
                $window,
                $consumed[$product->id] ?? 0,
                $firstAt[$product->id] ?? null,
                $expiredUnits[$product->id] ?? 0,
            ),
        ]);
    }

    /**
     * La couverture de tous les produits actifs.
     *
     * @return Collection<int, array{product: Product, coverage: Coverage}>
     */
    public function forActiveProducts(): Collection
    {
        $products = Product::query()->active()->get();
        $coverages = $this->forProducts($products);

        return $products->map(fn (Product $product) => [
            'product' => $product,
            'coverage' => $coverages->get($product->id),
        ]);
    }

    private function compute(Product $product, int $window, int $consumed, ?string $firstAt, int $expiredUnits): Coverage
    {
        // Les lots expirés ne peuvent plus être vendus : ils ne comptent pas dans le stock utilisable.
        $usable = max(0, $product->quantity - $expiredUnits);
        $minHistory = (int) config('stockflow.coverage.min_history_days');

        $history = $firstAt === null ? 0 : (int) floor(Carbon::parse($firstAt)->diffInDays(now()));

        if ($history < $minHistory) {
            return Coverage::undetermined(
                $usable,
                max(0, $history),
                "Historique insuffisant : il faut au moins {$minHistory} jours de mouvements pour estimer la couverture."
            );
        }

        if ($consumed === 0) {
            return Coverage::undetermined(
                $usable,
                $history,
                'Aucune vente ni consommation interne récente : la couverture ne peut pas être estimée.'
            );
        }

        // Sur un historique plus court que la fenêtre, on moyenne sur l'historique disponible.
        $daily = $consumed / min($window, $history);
        $days = $usable / $daily;

        $level = match (true) {
            $days < (int) config('stockflow.coverage.critical_days') => CoverageLevel::Critical,
            $days < (int) config('stockflow.coverage.attention_days') => CoverageLevel::Attention,
            default => CoverageLevel::Comfortable,
        };

        return new Coverage(
            level: $level,
            usableQuantity: $usable,
            historyDays: $history,
            dailyConsumption: $daily,
            days: $days,
            // Au-delà de dix ans, une date n'a plus de sens.
            stockoutDate: $days <= 3650 ? now()->addMinutes((int) round($days * 1440))->startOfDay() : null,
        );
    }
}