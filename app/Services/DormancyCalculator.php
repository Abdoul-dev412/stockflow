<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DormancyCalculator
{
    /** Seules ces sorties comptent comme activité d'un produit : elles remettent le compteur de dormance à zéro. */
    public const CONSUMPTION_REASONS = [MovementReason::Sale, MovementReason::InternalUse];

    public function thresholdDays(): int
    {
        return (int) config('stockflow.dormancy.days');
    }

    /**
     * Jours écoulés, à la date $at, depuis la dernière vente ou consommation du produit
     * (à défaut, depuis son premier mouvement). Null si le produit n'a aucun mouvement.
     */
    public function idleDays(Product $product, CarbonInterface $at, ?int $excludeMovementId = null): ?int
    {
        $reference = $this->referenceDate($product->id, $at, $excludeMovementId);

        return $reference === null ? null : (int) floor(Carbon::parse($reference)->diffInDays($at, false));
    }

    /**
     * Produits actifs, en stock, sans vente ni consommation depuis le seuil. Les plus inactifs d'abord.
     * Même définition que idleDays(), calculée pour tous les produits en une seule requête.
     *
     * @return Collection<int, array{product: Product, reference_at: Carbon, idle_days: int, capital: string}>
     */
    public function dormantProducts(?CarbonInterface $at = null): Collection
    {
        $at ??= now();
        $limit = $at->copy()->subDays($this->thresholdDays());

        $reasons = array_map(fn (MovementReason $reason) => $reason->value, self::CONSUMPTION_REASONS);
        $placeholders = implode(',', array_fill(0, count($reasons), '?'));

        $rows = DB::select(<<<SQL
            SELECT t.id, t.reference_at, t.capital
            FROM (
                SELECT p.id,
                       p.quantity * p.purchase_price AS capital,
                       COALESCE(
                           (SELECT MAX(m.occurred_at) FROM stock_movements m
                             WHERE m.product_id = p.id AND m.type = 'exit' AND m.status = 'applied'
                               AND m.reason_code IN ({$placeholders}) AND m.occurred_at <= ?
                               AND NOT EXISTS (SELECT 1 FROM stock_movements c WHERE c.corrects_movement_id = m.id)),
                           (SELECT MIN(f.occurred_at) FROM stock_movements f
                             WHERE f.product_id = p.id AND f.status = 'applied' AND f.occurred_at <= ?)
                       ) AS reference_at
                FROM products p
                WHERE p.is_active = 1 AND p.deleted_at IS NULL AND p.quantity > 0
            ) t
            WHERE t.reference_at IS NOT NULL AND t.reference_at <= ?
            ORDER BY t.reference_at ASC, t.id ASC
            SQL, [...$reasons, $at->toDateTimeString(), $at->toDateTimeString(), $limit->toDateTimeString()]);

        $products = Product::query()
            ->whereIn('id', array_column($rows, 'id'))
            ->with('category:id,name')
            ->get()
            ->keyBy('id');

        return collect($rows)->map(function ($row) use ($products, $at) {
            $reference = Carbon::parse($row->reference_at);

            return [
                'product' => $products[$row->id],
                'reference_at' => $reference,
                'idle_days' => (int) floor($reference->diffInDays($at, false)),
                'capital' => (string) $row->capital,
            ];
        })->values();
    }

    private function referenceDate(int $productId, CarbonInterface $at, ?int $excludeMovementId): ?string
    {
        $lastConsumption = StockMovement::query()
            ->where('product_id', $productId)
            ->where('type', MovementType::Exit->value)
            ->where('status', MovementStatus::Applied->value)
            ->whereDoesntHave('correction')
            ->whereIn('reason_code', array_map(fn (MovementReason $reason) => $reason->value, self::CONSUMPTION_REASONS))
            ->when($excludeMovementId, fn ($query, $id) => $query->where('id', '<>', $id))
            ->where('occurred_at', '<=', $at)
            ->max('occurred_at');

        // Sans vente ni consommation passée, la référence est le premier mouvement du produit.
        return $lastConsumption ?? StockMovement::query()
            ->where('product_id', $productId)
            ->where('status', MovementStatus::Applied->value)
            ->when($excludeMovementId, fn ($query, $id) => $query->where('id', '<>', $id))
            ->where('occurred_at', '<=', $at)
            ->min('occurred_at');
    }
}