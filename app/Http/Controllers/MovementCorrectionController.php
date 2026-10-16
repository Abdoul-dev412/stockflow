<?php

namespace App\Http\Controllers;

use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Exceptions\StockMovementException;
use App\Http\Requests\CorrectionRequest;
use App\Models\StockMovement;
use App\Services\StockMovementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MovementCorrectionController extends Controller
{
    public function __construct(private StockMovementService $stock)
    {
    }

    public function create(StockMovement $movement): View|RedirectResponse
    {
        Gate::authorize('correct', $movement);

        if ($reason = $this->blockedReason($movement)) {
            return redirect()->route('movements.index')->withErrors(['form' => $reason]);
        }

        $movement->load('product');

        return view('movements.correct', [
            'movement' => $movement,
            'resultingStock' => $movement->product->quantity - $movement->signedQuantity(),
        ]);
    }

    public function store(CorrectionRequest $request, StockMovement $movement): RedirectResponse
    {
        try {
            $this->stock->recordCorrection($movement, $request->user(), $request->validated('reason'));
        } catch (StockMovementException $e) {
            return back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('movements.index')->with('status', 'Correction enregistrée.');
    }

    /** Aide à l'affichage : le service reste l'autorité qui tranche. */
    private function blockedReason(StockMovement $movement): ?string
    {
        return match (true) {
            $movement->status !== MovementStatus::Applied => 'Seul un mouvement appliqué peut être corrigé.',
            $movement->type === MovementType::Correction => 'Une correction ne peut pas être corrigée.',
            $movement->correction()->exists() => 'Ce mouvement a déjà été corrigé.',
            default => null,
        };
    }
}