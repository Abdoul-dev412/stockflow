<?php

namespace App\Services;

use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AbcClassifier
{
    /**
     * Produits actifs classés par valeur de consommation décroissante.
     * Un produit est de classe A tant que la part cumulée AVANT lui est inférieure au seuil A (80 %),
     * B tant qu'elle est inférieure au seuil B (95 %), C ensuite. Les produits sans valeur ne sont pas classés.
     *
     * @return Collection<int, array{product: Product, units: int, value: string, share: float, cumulative: float, class: string}>
     */
    public function classify(?CarbonInterface $at = null): Collection
    {
        $at ??= now();
        $from = $at->copy()->subDays((int) config('stockflow.abc.window_days'));
        $reasons = array_map(fn ($reason) => $reason->value, DormancyCalculator::CONSUMPTION_REASONS);

        $rows = StockMovement::query()
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.type', MovementType::Exit->value)
            ->where('stock_movements.status', MovementStatus::Applied->value)
            ->whereIn('stock_movements.reason_code', $reasons)
            ->whereBetween('stock_movements.occurred_at', [$from, $at])
            ->whereDoesntHave('correction')
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->groupBy('stock_movements.product_id')
            ->selectRaw('stock_movements.product_id, SUM(stock_movements.quantity) as units, SUM(stock_movements.quantity * products.purchase_price) as value')
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'units' => (int) $row->units,
                'cents' => Money::toCents((string) $row->value),
            ])
            ->filter(fn (array $row) => $row['cents'] > 0)
            ->sort(fn (array $a, array $b) => [$b['cents'], $a['product_id']] <=> [$a['cents'], $b['product_id']])
            ->values();

        if ($rows->isEmpty()) {
            return collect();
        }

        $products = Product::query()
            ->whereIn('id', $rows->pluck('product_id'))
            ->with('category:id,name')
            ->get()
            ->keyBy('id');

        $total = $rows->sum('cents');
        $aLimit = (int) config('stockflow.abc.a_percent');
        $bLimit = (int) config('stockflow.abc.b_percent');
        $before = 0;

        return $rows->map(function (array $row) use (&$before, $total, $aLimit, $bLimit, $products) {
            // Comparaisons sur des entiers : pas d'erreur d'arrondi au seuil exact.
            $class = $before * 100 < $aLimit * $total ? 'A' : ($before * 100 < $bLimit * $total ? 'B' : 'C');
            $cumulative = $before + $row['cents'];

            $item = [
                'product' => $products[$row['product_id']],
                'units' => $row['units'],
                'value' => Money::fromCents($row['cents']),
                'share' => round($row['cents'] / $total * 100, 1),
                'cumulative' => round($cumulative / $total * 100, 1),
                'class' => $class,
            ];

            $before = $cumulative;

            return $item;
        })->values();
    }

    /** @return array<string, array{products: int, value: string, share: float}> */
    public function summary(Collection $classified): array
    {
        $total = (int) $classified->sum(fn (array $row) => Money::toCents($row['value']));
        $summary = [];

        foreach (['A', 'B', 'C'] as $class) {
            $rows = $classified->where('class', $class);
            $cents = (int) $rows->sum(fn (array $row) => Money::toCents($row['value']));

            $summary[$class] = [
                'products' => $rows->count(),
                'value' => Money::fromCents($cents),
                'share' => $total > 0 ? round($cents / $total * 100, 1) : 0.0,
            ];
        }

        return $summary;
    }
}