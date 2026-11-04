<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockValuation
{
    /** Pertes, dans l'ordre d'affichage. */
    public const LOSS_REASONS = [
        MovementReason::Expiry, MovementReason::Breakage, MovementReason::Loss, MovementReason::Theft,
    ];

    /**
     * Valeur au prix d'achat actuel de tous les produits non supprimés (inactifs compris : leur stock existe).
     *
     * @return array{products: int, in_stock: int, units: int, value: string, expired_units: int, expired_value: string}
     */
    public function summary(): array
    {
        $total = Product::query()
            ->selectRaw('COUNT(*) as products,
                COALESCE(SUM(quantity > 0), 0) as in_stock,
                COALESCE(SUM(quantity), 0) as units,
                COALESCE(SUM(quantity * purchase_price), 0) as value')
            ->toBase()
            ->first();

        // Lots expirés encore en stock, valorisés au même prix : la valeur à retirer du stock.
        $expired = DB::table('stock_lots')
            ->join('products', 'products.id', '=', 'stock_lots.product_id')
            ->whereNull('products.deleted_at')
            ->where('stock_lots.quantity_remaining', '>', 0)
            ->whereNotNull('stock_lots.expires_at')
            ->where('stock_lots.expires_at', '<', today()->toDateString())
            ->selectRaw('COALESCE(SUM(stock_lots.quantity_remaining), 0) as units,
                COALESCE(SUM(stock_lots.quantity_remaining * products.purchase_price), 0) as value')
            ->first();

        return [
            'products' => (int) $total->products,
            'in_stock' => (int) $total->in_stock,
            'units' => (int) $total->units,
            'value' => (string) $total->value,
            'expired_units' => (int) $expired->units,
            'expired_value' => (string) $expired->value,
        ];
    }

    /** @return Collection<int, array{id: int, name: string, products: int, in_stock: int, units: int, value: string, cents: int}> */
    public function byCategory(): Collection
    {
        return DB::table('products')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereNull('products.deleted_at')
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.id, categories.name,
                COUNT(*) as products,
                COALESCE(SUM(products.quantity > 0), 0) as in_stock,
                COALESCE(SUM(products.quantity), 0) as units,
                COALESCE(SUM(products.quantity * products.purchase_price), 0) as value')
            ->orderByRaw('SUM(products.quantity * products.purchase_price) DESC')
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'products' => (int) $row->products,
                'in_stock' => (int) $row->in_stock,
                'units' => (int) $row->units,
                'value' => (string) $row->value,
                'cents' => Money::toCents((string) $row->value),
            ])
            ->values();
    }

    /**
     * Coût des pertes par motif sur les $days derniers jours, au prix d'achat actuel.
     * Les quatre motifs sont toujours présents (à zéro s'il n'y a rien). La largeur sert au graphique en barres.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, total_value: string, total_cents: int}
     */
    public function lossCost(int $days, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        $byReason = StockMovement::query()
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.type', MovementType::Exit->value)
            ->where('stock_movements.status', MovementStatus::Applied->value)
            ->whereIn('stock_movements.reason_code', array_map(fn (MovementReason $reason) => $reason->value, self::LOSS_REASONS))
            ->whereBetween('stock_movements.occurred_at', [$at->copy()->subDays($days), $at])
            ->whereDoesntHave('correction')
            ->groupBy('stock_movements.reason_code')
            ->selectRaw('stock_movements.reason_code, COUNT(*) as movements,
                SUM(stock_movements.quantity) as units,
                SUM(stock_movements.quantity * products.purchase_price) as cost')
            ->toBase()
            ->get()
            ->keyBy('reason_code');

        $rows = collect(self::LOSS_REASONS)->map(function (MovementReason $reason) use ($byReason) {
            $row = $byReason->get($reason->value);
            $cents = $row ? Money::toCents((string) $row->cost) : 0;

            return [
                'reason' => $reason,
                'label' => $reason->label(),
                'movements' => $row ? (int) $row->movements : 0,
                'units' => $row ? (int) $row->units : 0,
                'value' => Money::fromCents($cents),
                'cents' => $cents,
            ];
        });

        $max = (int) $rows->max('cents');
        $totalCents = (int) $rows->sum('cents');

        return [
            'rows' => $rows->map(fn (array $row) => $row + [
                'width' => $max > 0 ? (int) round($row['cents'] / $max * 100) : 0,
            ])->values(),
            'total_value' => Money::fromCents($totalCents),
            'total_cents' => $totalCents,
        ];
    }
}