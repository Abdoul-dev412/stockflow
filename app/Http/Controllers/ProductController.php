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
use App\Enums\AuditAction;
use App\Services\AuditLogger;
use App\Support\ProductFilters;

class ProductController extends Controller
{
    private const SORTABLE = ['name', 'sku', 'quantity', 'purchase_price'];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $filters = ProductFilters::fromRequest($request);
        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? $request->query('sort') : 'name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $products = Product::query()
            ->with(['category:id,name', 'supplier:id,name'])
            ->filtered($filters)
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'status' => $filters['status'],
            'stock' => $filters['stock'],
            'categoryId' => $filters['category'],
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('products.create', $this->formOptions());
    }

    public function store(ProductRequest $request, AuditLogger $audit): RedirectResponse
    {
        $product = $audit->createWithAudit(
            new Product($request->validated()),
            $request->user(),
            AuditAction::ProductCreated,
        );

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

    public function update(ProductRequest $request, Product $product, AuditLogger $audit): RedirectResponse
    {
        $audit->updateWithAudit(
            $product->fill($request->validated()),
            $request->user(),
            AuditAction::ProductUpdated,
        );

        return redirect()->route('products.show', $product)
            ->with('status', 'Produit modifié.');
    }

    public function toggle(Request $request, Product $product, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $product);

        $audit->toggleWithAudit(
            $product,
            $request->user(),
            AuditAction::ProductActivated,
            AuditAction::ProductDeactivated,
        );

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