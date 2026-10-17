<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use App\Enums\AuditAction;
use App\Services\AuditLogger;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Category::class);

        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : null;

        $categories = Category::query()
            ->withCount('products')
            ->when($request->filled('search'), fn ($query) => $query->where(
                'name', 'like', '%'.$request->string('search')->trim().'%'
            ))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('categories.index', compact('categories', 'status'));
    }

    public function create(): View
    {
        Gate::authorize('create', Category::class);

        return view('categories.create');
    }

    public function store(StoreCategoryRequest $request, AuditLogger $audit): RedirectResponse
    {
        $audit->createWithAudit(
            new Category($request->validated()),
            $request->user(),
            AuditAction::CategoryCreated,
        );

        return redirect()->route('categories.index')
            ->with('status', 'Catégorie créée.');
    }

    public function show(Category $category): View
    {
        Gate::authorize('view', $category);

        $products = $category->products()->orderBy('name')->paginate(10);

        return view('categories.show', compact('category', 'products'));
    }

    public function edit(Category $category): View
    {
        Gate::authorize('update', $category);

        return view('categories.edit', compact('category'));
    }

    public function update(UpdateCategoryRequest $request, Category $category, AuditLogger $audit): RedirectResponse
    {
        $audit->updateWithAudit(
            $category->fill($request->validated()),
            $request->user(),
            AuditAction::CategoryUpdated,
        );

        return redirect()->route('categories.index')
            ->with('status', 'Catégorie modifiée.');
    }

    public function toggle(Request $request, Category $category, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $category);

        $audit->toggleWithAudit(
            $category,
            $request->user(),
            AuditAction::CategoryActivated,
            AuditAction::CategoryDeactivated,
        );

        return back()->with('status', $category->is_active
            ? 'Catégorie réactivée.'
            : 'Catégorie désactivée.');
    }
}