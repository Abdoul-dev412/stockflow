<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use App\Enums\AuditAction;
use App\Services\AuditLogger;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Supplier::class);

        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : null;

        $search = trim((string) $request->query('search', ''));

        $suppliers = Supplier::query()
            ->withCount('products')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('suppliers.index', compact('suppliers', 'status'));
    }

    public function create(): View
    {
        Gate::authorize('create', Supplier::class);

        return view('suppliers.create');
    }

    public function store(SupplierRequest $request, AuditLogger $audit): RedirectResponse
    {
        $audit->createWithAudit(
            new Supplier($request->validated()),
            $request->user(),
            AuditAction::SupplierCreated,
        );

        return redirect()->route('suppliers.index')
            ->with('status', 'Fournisseur ajouté.');
    }

    public function show(Supplier $supplier): View
    {
        Gate::authorize('view', $supplier);

        $products = $supplier->products()->orderBy('name')->paginate(10);

        return view('suppliers.show', compact('supplier', 'products'));
    }

    public function edit(Supplier $supplier): View
    {
        Gate::authorize('update', $supplier);

        return view('suppliers.edit', compact('supplier'));
    }

    public function update(SupplierRequest $request, Supplier $supplier, AuditLogger $audit): RedirectResponse
    {
        $audit->updateWithAudit(
            $supplier->fill($request->validated()),
            $request->user(),
            AuditAction::SupplierUpdated,
        );

        return redirect()->route('suppliers.index')
            ->with('status', 'Fournisseur modifié.');
    }

    public function toggle(Request $request, Supplier $supplier, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $supplier);

        $audit->toggleWithAudit(
            $supplier,
            $request->user(),
            AuditAction::SupplierActivated,
            AuditAction::SupplierDeactivated,
        );

        return back()->with('status', $supplier->is_active
            ? 'Fournisseur réactivé.'
            : 'Fournisseur désactivé.');
    }
}