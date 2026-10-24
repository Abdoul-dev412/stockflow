<?php

namespace App\Http\Controllers;

use App\Exceptions\StockMovementException;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Services\StockMovementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpiryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $filters = $request->validate([
            'days' => ['nullable', Rule::in([7, 15, 30, 60, 90])],
        ]);

        $days = (int) ($filters['days'] ?? StockLot::EXPIRY_WARNING_DAYS);

        $lots = StockLot::query()
            ->inStock()
            ->whereNotNull('expires_at')
            ->ofActiveProducts()
            ->where('expires_at', '<=', today()->addDays($days)->toDateString())
            ->with('product:id,sku,name,unit,is_active')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('expiries.index', [
            'lots' => $lots,
            'days' => $days,
            'counts' => StockLot::expiryCounts($days),
        ]);
    }

    public function remove(Request $request, Product $product, StockMovementService $stock): RedirectResponse
    {
        Gate::authorize('create', StockMovement::class);

        try {
            $movement = $stock->removeExpiredLots($product, $request->user());
        } catch (StockMovementException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return back()->with('status', "{$movement->quantity} unité(s) expirée(s) retirée(s) du stock.");
    }
}