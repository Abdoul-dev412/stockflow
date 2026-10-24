<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\MovementStatus;
use App\Http\Requests\MovementFilterRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Support\CsvExport;
use App\Support\ProductFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function products(Request $request, AuditLogger $audit): StreamedResponse
    {
        $filters = ProductFilters::fromRequest($request);
        $this->trace($audit, $request, 'products', $filters);

        $rows = Product::query()
            ->with(['category:id,name', 'supplier:id,name'])
            ->filtered($filters)
            ->orderBy('name')
            ->orderBy('id')
            ->lazy(500)
            ->map(fn (Product $product) => [
                $product->sku,
                $product->barcode,
                $product->name,
                $product->category->name,
                $product->supplier?->name,
                $product->unit,
                CsvExport::decimal($product->purchase_price),
                CsvExport::decimal($product->sale_price),
                $product->quantity,
                $product->minimum_stock,
                $product->stockStatus()->label(),
                $product->is_active ? 'Actif' : 'Inactif',
                $product->created_at->format('d/m/Y H:i'),
                $product->updated_at->format('d/m/Y H:i'),
            ]);

        return CsvExport::download('produits', [
            'SKU', 'Code-barres', 'Produit', 'Catégorie', 'Fournisseur', 'Unité',
            "Prix d'achat", 'Prix de vente', 'Quantité', 'Seuil minimal',
            'Niveau de stock', 'Statut', 'Créé le', 'Modifié le',
        ], $rows);
    }

    public function stockLevels(Request $request, AuditLogger $audit): StreamedResponse
    {
        // Les niveaux de stock ne concernent que les produits actifs.
        $filters = Arr::except(ProductFilters::fromRequest($request), 'status');
        $this->trace($audit, $request, 'stock-levels', $filters);

        $rows = Product::query()
            ->active()
            ->with(['category:id,name', 'supplier:id,name'])
            ->filtered($filters)
            ->orderBy('name')
            ->orderBy('id')
            ->lazy(500)
            ->map(fn (Product $product) => [
                $product->sku,
                $product->name,
                $product->category->name,
                $product->supplier?->name,
                $product->unit,
                $product->quantity,
                $product->minimum_stock,
                $product->stockStatus()->label(),
                max(0, $product->minimum_stock - $product->quantity),
            ]);

        return CsvExport::download('niveaux-de-stock', [
            'SKU', 'Produit', 'Catégorie', 'Fournisseur', 'Unité',
            'Quantité', 'Seuil minimal', 'Niveau de stock', 'Manque pour atteindre le seuil',
        ], $rows);
    }

    public function movements(MovementFilterRequest $request, AuditLogger $audit): StreamedResponse
    {
        $filters = $request->validated();
        $this->trace($audit, $request, 'movements', $filters);

        $rows = StockMovement::query()
            ->with(['product', 'user:id,name', 'allocations.lot:id,lot_number'])
            ->filtered($filters)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->lazy(500)
            ->map(fn (StockMovement $movement) => [
                $movement->occurred_at->format('d/m/Y H:i'),
                $movement->recorded_at->format('d/m/Y H:i'),
                $movement->product->sku,
                $movement->product->name,
                $movement->type->label(),
                $movement->reason_code->label(),
                $movement->reason,
                $movement->status === MovementStatus::Applied ? $movement->signedQuantity() : null,
                $movement->quantity_before,
                $movement->quantity_after,
                $movement->user->name,
                $movement->status->label(),
                $movement->corrects_movement_id,
                $movement->allocations
                ->map(fn ($allocation) => $allocation->lot->lot_number.':'.$allocation->quantity)
                ->implode(' | '),
            ]);

        return CsvExport::download('mouvements', [
            "Date de l'opération", "Date d'enregistrement", 'SKU', 'Produit', 'Type', 'Motif',
            'Commentaire', 'Variation', 'Stock avant', 'Stock après', 'Utilisateur', 'Statut',
            'Corrige le mouvement n°', 'Lots',
        ], $rows);
    }

    /** Chaque export est tracé : qui, quoi, avec quels filtres. */
    private function trace(AuditLogger $audit, Request $request, string $export, array $filters): void
    {
        $applied = array_filter($filters, fn ($value) => $value !== null && $value !== '');

        $audit->record(
            $request->user(),
            AuditAction::DataExported,
            'export',
            ['export' => $export] + ($applied === [] ? [] : ['filters' => $applied]),
        );
    }
}