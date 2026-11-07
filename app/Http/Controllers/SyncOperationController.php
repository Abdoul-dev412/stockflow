<?php

namespace App\Http\Controllers;

use App\Enums\MovementStatus;
use App\Exceptions\SyncResolutionException;
use App\Models\Device;
use App\Models\StockLot;
use App\Models\SyncOperation;
use App\Services\SyncResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SyncOperationController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SyncOperation::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'conflict', 'pending_validation', 'rejected', 'applied', 'all'])],
            'device' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $status = $filters['status'] ?? 'open';

        $operations = SyncOperation::query()
            ->visibleTo($user)
            ->select('sync_operations.*')
            // Mouvements appliqués sur le produit depuis le moment du comptage : alerte pour l'administrateur.
            ->selectSub(
                fn ($query) => $query->from('stock_movements')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('stock_movements.product_id', 'sync_operations.product_id')
                    ->where('stock_movements.status', MovementStatus::Applied->value)
                    ->whereColumn('stock_movements.occurred_at', '>=', 'sync_operations.occurred_at'),
                'movements_since',
            )
            ->with(['product:id,sku,name,unit,quantity,tracks_lots', 'device:id,name', 'user:id,name', 'resolver:id,name'])
            ->when($status === 'open', fn ($query) => $query->whereIn('status', [
                MovementStatus::Conflict->value, MovementStatus::PendingValidation->value,
            ]))
            ->when(! in_array($status, ['open', 'all'], true), fn ($query) => $query->where('status', $status))
            ->when($filters['device'] ?? null, fn ($query, $id) => $query->where('device_id', $id))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $lotIds = $operations->getCollection()->map(fn (SyncOperation $op) => $op->payload['lot_id'] ?? null)->filter()->unique();

        return view('sync-operations.index', [
            'operations' => $operations,
            'status' => $status,
            'lots' => StockLot::query()->whereIn('id', $lotIds)->get(['id', 'lot_number', 'quantity_remaining'])->keyBy('id'),
            'devices' => Device::query()
                ->when(! $user->isAdmin(), fn ($query) => $query->where('user_id', $user->id))
                ->orderBy('name')
                ->get(['id', 'name']),
            'conflictCount' => SyncOperation::query()->visibleTo($user)->where('status', MovementStatus::Conflict->value)->count(),
            'pendingCount' => SyncOperation::query()->visibleTo($user)->where('status', MovementStatus::PendingValidation->value)->count(),
        ]);
    }

    public function resolve(Request $request, SyncOperation $operation, SyncResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['reject', 'partial', 'replay', 'approve', 'refuse'])],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $action = $data['action'];

        Gate::authorize(in_array($action, ['approve', 'refuse'], true) ? 'validateAdjustment' : 'resolve', $operation);

        $comment = filled($data['comment'] ?? null) ? trim($data['comment']) : null;

        if (in_array($action, ['reject', 'refuse'], true) && $comment === null) {
            return back()->withErrors(['comment' => 'Un commentaire est obligatoire pour rejeter ou refuser une opération.']);
        }

        $actor = $request->user();

        try {
            $result = match ($action) {
                'reject' => $resolver->rejectConflict($operation, $actor, $comment),
                'partial' => $resolver->applyAvailable($operation, $actor),
                'replay' => $resolver->replay($operation, $actor),
                'approve' => $resolver->approveAdjustment($operation, $actor),
                'refuse' => $resolver->refuseAdjustment($operation, $actor, $comment),
            };
        } catch (SyncResolutionException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        if ($result->status === MovementStatus::Conflict) {
            return back()->withErrors(['form' => "Toujours en conflit : {$result->reason}"]);
        }

        return back()->with('status', match ($action) {
            'reject' => 'Opération rejetée.',
            'partial' => 'Opération appliquée.',
            'replay' => 'Opération rejouée et appliquée.',
            'approve' => 'Inventaire validé et appliqué.',
            'refuse' => 'Inventaire refusé.',
        });
    }
}