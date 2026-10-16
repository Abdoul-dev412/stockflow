<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $filters = $request->validate([
            'level' => ['nullable', Rule::in(['low', 'out'])],
            'category' => ['nullable', 'integer', 'min:1'],
            'supplier' => ['nullable', 'integer', 'min:1'],
        ]);

        $level = $filters['level'] ?? null;
        $categoryId = $filters['category'] ?? null;
        $supplierId = $filters['supplier'] ?? null;

        $products = Product::query()
            ->active()
            ->with(['category:id,name', 'supplier:id,name,phone'])
            ->when($level === 'low', fn ($q) => $q->lowStock())
            ->when($level === 'out', fn ($q) => $q->outOfStock())
            ->when($level === null, fn ($q) => $q->needsRestock())
            ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
            ->when($supplierId, fn ($q, $id) => $q->where('supplier_id', $id))
            ->mostUrgentFirst()
            ->paginate(15)
            ->withQueryString();

        return view('alerts.index', [
            'products' => $products,
            'counts' => Product::stockCounts(),
            'level' => $level,
            'categoryId' => $categoryId,
            'supplierId' => $supplierId,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }
}