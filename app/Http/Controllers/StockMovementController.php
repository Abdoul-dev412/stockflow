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
use App\Http\Requests\MovementFilterRequest;
use App\Models\StockLot;

class StockMovementController extends Controller
{
    public function __construct(private StockMovementService $stock)
    {
    }

    public function index(MovementFilterRequest $request): View
    {
        Gate::authorize('viewAny', StockMovement::class);

        $filters = $request->validated();

        $movements = StockMovement::query()
            ->with(['product', 'user:id,name', 'allocations.lot:id,lot_number'])
            ->withExists('correction')
            ->filtered($filters)
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
            'lots' => $this->lotOptions($product, $movementType),
        ]);
    }

    public function store(StockMovementRequest $request, Product $product, string $type): RedirectResponse
    {
        $movementType = $this->resolveType($type);
        $data = $request->validated();
        $user = $request->user();

        // Les champs de lot ne comptent que pour un produit réellement suivi par lots.
        $tracked = (bool) $product->tracks_lots;
        $lotNumber = $tracked ? ($data['lot_number'] ?? null) : null;
        $expiresAt = $tracked && ! empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;
        $lotId = $tracked && ! empty($data['lot_id']) ? (int) $data['lot_id'] : null;

        try {
            $movement = match ($movementType) {
                MovementType::Entry => $this->stock->recordEntry(
                    $product, (int) $data['quantity'], $user,
                    MovementReason::from($data['reason_code']), $data['reason'] ?? null,
                    lotNumber: $lotNumber, expiresAt: $expiresAt,
                ),
                MovementType::Exit => $this->stock->recordExit(
                    $product, (int) $data['quantity'], $user,
                    MovementReason::from($data['reason_code']), $data['reason'] ?? null,
                    lotId: $lotId,
                ),
                MovementType::Adjustment => $this->stock->recordAdjustment(
                    $product, (int) $data['counted_quantity'], $user, $data['reason'] ?? null,
                    lotId: $lotId,
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

    /** @return array<int, array<string, mixed>> lots proposés par le formulaire, dans l'ordre FEFO */
    private function lotOptions(Product $product, MovementType $type): array
    {
        if (! $product->tracks_lots) {
            return [];
        }

        $query = $product->lots()->fefo();

        // Une sortie ne propose que des lots non vides ; l'entrée et l'inventaire voient tous les lots.
        if ($type === MovementType::Exit) {
            $query->inStock();
        }

        return $query->get()->map(fn (StockLot $lot) => [
            'id' => $lot->id,
            'number' => $lot->lot_number,
            'expires_at' => $lot->expires_at?->toDateString(),
            'expired' => $lot->isExpired(),
            'quantity' => $lot->quantity_remaining,
            'label' => $lot->expiryLabel(),
        ])->values()->all();
    }
}