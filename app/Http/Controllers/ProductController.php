<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductController extends Controller
{
    private const SORTABLE = ['name', 'sku', 'quantity', 'purchase_price'];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status') : null;
        $stock = in_array($request->query('stock'), ['normal', 'low', 'out'], true)
            ? $request->query('stock') : null;
        $categoryId = $request->integer('category') ?: null;
        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? $request->query('sort') : 'name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $products = Product::query()
            ->with(['category:id,name', 'supplier:id,name'])
            ->when($search !== '', fn ($q) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
            ))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($stock === 'normal', fn ($q) => $q->normalStock())
            ->when($stock === 'low', fn ($q) => $q->lowStock())
            ->when($stock === 'out', fn ($q) => $q->outOfStock())
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $categories = Category::orderBy('name')->get(['id', 'name']);

        return view('products.index', compact(
            'products', 'categories', 'status', 'stock', 'categoryId', 'sort', 'direction'
        ));
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('products.create', $this->formOptions());
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->validated());

        return redirect()->route('products.show', $product)
            ->with('status', 'Produit créé. Son stock initial est à 0.');
    }

    public function show(Product $product): View
{
    Gate::authorize('view', $product);

    $product->load(['category', 'supplier']);

    $movements = $product->movements()
        ->with('user:id,name')
        ->orderByDesc('occurred_at')
        ->orderByDesc('id')
        ->limit(10)
        ->get();

    return view('products.show', compact('product', 'movements'));
}
    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('products.edit', ['product' => $product] + $this->formOptions($product));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return redirect()->route('products.show', $product)
            ->with('status', 'Produit modifié.');
    }

    public function toggle(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $product->update(['is_active' => ! $product->is_active]);

        return back()->with('status', $product->is_active
            ? 'Produit réactivé.'
            : 'Produit désactivé.');
    }

    private function formOptions(?Product $product = null): array
    {
        return [
            'categories' => Category::query()
                ->where(fn ($q) => $q->where('is_active', true)
                    ->when($product, fn ($q) => $q->orWhere('id', $product->category_id)))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
            'suppliers' => Supplier::query()
                ->where(fn ($q) => $q->where('is_active', true)
                    ->when($product?->supplier_id, fn ($q) => $q->orWhere('id', $product->supplier_id)))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
        ];
    }
}