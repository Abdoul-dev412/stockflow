<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\ReplenishmentAdvisor;
use App\Services\StockMovementService;
use App\Support\ReplenishmentSuggestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReplenishmentController extends Controller
{
    public function index(ReplenishmentAdvisor $advisor): View
    {
        Gate::authorize('viewAny', Product::class);

        $groups = $advisor->suggestions()
            ->groupBy(fn (ReplenishmentSuggestion $suggestion) => $suggestion->product->supplier_id ?? 0)
            ->map(fn (Collection $items) => [
                'supplier' => $items->first()->product->supplier,
                'items' => $items,
            ])
            // Les produits sans fournisseur en dernier ; le reste garde l'ordre d'urgence.
            ->sortBy(fn (array $group) => $group['supplier'] === null ? 1 : 0)
            ->values();

        return view('replenishment.index', [
            'groups' => $groups,
            'prepared' => session('prepared'),
        ]);
    }

    public function prepare(
        Request $request,
        Supplier $supplier,
        ReplenishmentAdvisor $advisor,
        AuditLogger $audit,
    ): RedirectResponse {
        Gate::authorize('viewAny', Product::class);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.include' => ['nullable', 'boolean'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:'.StockMovementService::MAX_STOCK],
        ], [
            'items.required' => 'Sélectionne au moins un produit.',
            'items.*.quantity.integer' => 'Les quantités doivent être des nombres entiers.',
            'items.*.quantity.min' => 'Les quantités doivent être strictement positives.',
            'items.*.quantity.max' => 'Une quantité est trop élevée.',
        ]);

        if (! $supplier->is_active) {
            return back()->withErrors(['form' => 'Ce fournisseur est désactivé : aucun message ne peut lui être préparé.']);
        }

        $selected = collect($data['items'])->filter(fn (array $item) => ! empty($item['include']));

        if ($selected->isEmpty()) {
            return back()->withErrors(['items' => 'Sélectionne au moins un produit.'])->withInput();
        }

        if ($selected->contains(fn (array $item) => empty($item['quantity']))) {
            return back()->withErrors(['items' => 'Indique une quantité pour chaque produit sélectionné.'])->withInput();
        }

        $ids = $selected->keys()->map(fn ($id) => (int) $id)->all();

        // Les produits sont relus en base : on ne fait confiance à rien de ce que le formulaire affirme.
        $products = Product::query()
            ->active()
            ->where('supplier_id', $supplier->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($ids)) {
            return back()->withErrors([
                'form' => "Un des produits sélectionnés est inactif ou n'est pas rattaché à ce fournisseur.",
            ])->withInput();
        }

        $items = $selected
            ->map(fn (array $item, $id) => ['product' => $products[(int) $id], 'quantity' => (int) $item['quantity']])
            ->values();

        $message = $advisor->message($supplier, $items, $request->user());

        $audit->record($request->user(), AuditAction::ReplenishmentPrepared, $supplier, [
            'items' => $items->map(fn (array $item) => "{$item['product']->sku} × {$item['quantity']}")->implode(', '),
        ]);

        return redirect()->route('replenishment.index')->with('prepared', [
            'supplier' => $supplier->name,
            'message' => $message,
            'whatsapp' => $advisor->whatsappUrl($supplier, $message),
            'mailto' => $advisor->mailtoUrl($supplier, 'Commande', $message),
        ]);
    }
}