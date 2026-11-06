<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\Supplier;
use Carbon\CarbonInterface;

class SyncCatalog
{
    /**
     * Changements du catalogue depuis $since (tout le catalogue si null).
     * Aucun prix, aucun utilisateur, aucune donnée d'audit : seulement le nécessaire au travail hors ligne.
     */
    public function changes(?CarbonInterface $since): array
    {
        // Le curseur recule de quelques secondes : une transaction validée juste après la lecture
        // est captée au tour suivant. Les lignes revues deux fois sont sans danger (mise à jour par identifiant).
        $cursor = now()->subSeconds((int) config('stockflow.sync.cursor_lag_seconds'));

        $since = $since?->copy();
        $recent = fn ($query) => $since === null ? $query : $query->where('updated_at', '>=', $since);

        $products = $recent(Product::withTrashed()->when($since === null, fn ($query) => $query->whereNull('deleted_at')))
            ->orderBy('id')
            ->get([
                'id', 'sku', 'barcode', 'name', 'category_id', 'supplier_id', 'unit', 'quantity', 'minimum_stock',
                'tracks_lots', 'is_perishable', 'target_cover_days', 'is_active', 'deleted_at', 'updated_at',
            ]);

        $categories = $recent(Category::query())->orderBy('id')->get(['id', 'name', 'is_active', 'updated_at']);

        $suppliers = $recent(Supplier::query())->orderBy('id')
            ->get(['id', 'name', 'contact_name', 'phone', 'lead_time_days', 'is_active', 'updated_at']);

        // Première synchronisation : seulement les lots ouverts. Ensuite : tout lot modifié (y compris vidé).
        $lots = StockLot::query()
            ->when(
                $since !== null,
                fn ($query) => $query->where('updated_at', '>=', $since),
                fn ($query) => $query->where('quantity_remaining', '>', 0),
            )
            ->orderBy('id')
            ->get(['id', 'product_id', 'lot_number', 'expires_at', 'quantity_remaining', 'updated_at']);

        return [
            'full' => $since === null,
            'cursor' => $cursor->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'category_id' => $product->category_id,
                'supplier_id' => $product->supplier_id,
                'unit' => $product->unit,
                'quantity' => $product->quantity,
                'minimum_stock' => $product->minimum_stock,
                'tracks_lots' => $product->tracks_lots,
                'is_perishable' => $product->is_perishable,
                'target_cover_days' => $product->target_cover_days,
                'is_active' => $product->is_active,
                'deleted' => $product->deleted_at !== null,
                'updated_at' => $product->updated_at->toIso8601String(),
            ])->values()->all(),
            'categories' => $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'is_active' => $category->is_active,
                'updated_at' => $category->updated_at->toIso8601String(),
            ])->values()->all(),
            'suppliers' => $suppliers->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact_name' => $supplier->contact_name,
                'phone' => $supplier->phone,
                'lead_time_days' => $supplier->lead_time_days,
                'is_active' => $supplier->is_active,
                'updated_at' => $supplier->updated_at->toIso8601String(),
            ])->values()->all(),
            'lots' => $lots->map(fn (StockLot $lot) => [
                'id' => $lot->id,
                'product_id' => $lot->product_id,
                'lot_number' => $lot->lot_number,
                'expires_at' => $lot->expires_at?->toDateString(),
                'quantity_remaining' => $lot->quantity_remaining,
                'updated_at' => $lot->updated_at->toIso8601String(),
            ])->values()->all(),
            'settings' => [
                'coverage' => config('stockflow.coverage'),
                'replenishment' => config('stockflow.replenishment'),
                'dormancy_days' => (int) config('stockflow.dormancy.days'),
                'expiry_warning_days' => StockLot::EXPIRY_WARNING_DAYS,
            ],
        ];
    }
}