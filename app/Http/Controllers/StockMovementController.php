<?php

namespace App\Http\Controllers;

use App\Enums\MovementReason;
use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Http\Requests\StockMovementRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockMovementController extends Controller
{
    public function __construct(private StockMovementService $stock)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', StockMovement::class);

        $filters = $request->validate([
            'product' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(MovementType::class)],
            'user' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $movements = StockMovement::query()
            ->with(['product', 'user:id,name'])
            ->withExists('correction')
            ->when($filters['product'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($search !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p->withTrashed()
                ->where(fn ($p) => $p->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%"))))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('occurred_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('occurred_at', '<=', Carbon::parse($date)->endOfDay()))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('movements.index', [
            'movements' => $movements,
            'productFilter' => isset($filters['product']) ? Product::withTrashed()->find($filters['product']) : null,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'types' => MovementType::cases(),
        ]);
    }

    public function create(Product $product, string $type): View|RedirectResponse
    {
        Gate::authorize('create', StockMovement::class);

        $movementType = $this->resolveType($type);

        if (! $product->is_active) {
            return redirect()->route('products.show', $product)->withErrors([
                'form' => 'Un produit inactif ne peut plus recevoir de nouvelles opérations de stock.',
            ]);
        }

        return view('movements.create', [
            'product' => $product,
            'type' => $movementType,
            'reasons' => $movementType === MovementType::Adjustment ? [] : $movementType->allowedReasons(),
        ]);
    }

    public function store(StockMovementRequest $request, Product $product, string $type): RedirectResponse
    {
        $movementType = $this->resolveType($type);
        $data = $request->validated();
        $user = $request->user();

        try {
            $movement = match ($movementType) {
                MovementType::Entry => $this->stock->recordEntry(
                    $product, (int) $data['quantity'], $user,
                    MovementReason::from($data['reason_code']), $data['reason'] ?? null,
                ),
                MovementType::Exit => $this->stock->recordExit(
                    $product, (int) $data['quantity'], $user,
                    MovementReason::from($data['reason_code']), $data['reason'] ?? null,
                ),
                MovementType::Adjustment => $this->stock->recordAdjustment(
                    $product, (int) $data['counted_quantity'], $user, $data['reason'] ?? null,
                ),
            };
        } catch (InsufficientStockException $e) {
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        } catch (StockMovementException $e) {
            return back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        $signed = $movement->signedQuantity();

        return redirect()->route('products.show', $product)->with('status', sprintf(
            '%s enregistrée : %s%d (nouveau stock : %d).',
            $movement->type->label(),
            $signed > 0 ? '+' : '',
            $signed,
            $movement->quantity_after,
        ));
    }

    private function resolveType(string $type): MovementType
    {
        $movementType = MovementType::tryFrom($type);

        abort_unless(
            in_array($movementType, [MovementType::Entry, MovementType::Exit, MovementType::Adjustment], true),
            404,
        );

        return $movementType;
    }
}