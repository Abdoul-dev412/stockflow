<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MovementStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Exceptions\SyncResolutionException;
use App\Models\StockMovement;
use App\Models\SyncOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SyncResolver
{
    public function __construct(
        private SyncService $sync,
        private StockMovementService $stock,
        private AuditLogger $audit,
    ) {
    }

    // ------------------------------------------------------------------ Conflits

    
    public function rejectConflict(SyncOperation $operation, User $actor, string $comment): SyncOperation
    {
        return DB::transaction(function () use ($operation, $actor, $comment) {
            $locked = $this->lock($operation, MovementStatus::Conflict);

            $this->finish($locked, MovementStatus::Rejected, "Rejetée par {$actor->name} : {$comment}", [], null, $actor);
            $this->record($actor, AuditAction::SyncConflictResolved, $locked, [
                'resolution' => 'rejected',
                'comment' => $comment,
            ]);

            return $locked;
        }, 3);
    }


    /**
     * Applique la quantité demandée si le stock le permet maintenant, sinon tout le stock utilisable.
     */
    public function applyAvailable(SyncOperation $operation, User $actor): SyncOperation
    {
        return DB::transaction(function () use ($operation, $actor) {
            $locked = $this->lock($operation, MovementStatus::Conflict);

            $this->assertOwnerIsActive($locked);

            $requested = (int) ($locked->payload['quantity'] ?? 0);

            try {
                [$movement, $notes] = $this->sync->applyExit($locked);
                $applied = $requested;
            } catch (InsufficientStockException $e) {
                if ($e->available < 1) {
                    throw new SyncResolutionException('Aucun stock utilisable : rien à appliquer. Enregistre une entrée ou rejette cette opération.');
                }

                try {
                    [$movement, $notes] = $this->sync->applyExit($locked, $e->available);
                } catch (StockMovementException $inner) {
                    throw new SyncResolutionException($inner->getMessage());
                }

                $applied = $e->available;
                $notes[] = "Quantité ajustée à la disponibilité : {$applied} sur {$requested} demandée(s).";
            } catch (StockMovementException $e) {
                throw new SyncResolutionException($e->getMessage());
            }

            $this->finish($locked, MovementStatus::Applied, null, $notes, $movement, $actor);
            $this->record($actor, AuditAction::SyncConflictResolved, $locked, [
                'resolution' => $applied === $requested ? 'applied_in_full' : 'partial',
                'requested' => $requested,
                'applied' => $applied,
            ]);

            return $locked;
        }, 3);
    }
    /**
     * Rejoue l'opération telle quelle, après qu'une entrée ou un ajustement a rétabli le stock.
     * Si le conflit persiste, l'opération reste en conflit et sa raison est mise à jour.
     */
    public function replay(SyncOperation $operation, User $actor): SyncOperation
    {
        return DB::transaction(function () use ($operation, $actor) {
            $locked = $this->lock($operation, MovementStatus::Conflict);
            $this->assertOwnerIsActive($locked);

            try {
                [$movement, $notes] = $this->sync->applyExit($locked);
            } catch (InsufficientStockException $e) {
                $locked->forceFill(['reason' => $e->getMessage()])->save();

                return $locked;
            } catch (StockMovementException $e) {
                throw new SyncResolutionException($e->getMessage());
            }

            $notes[] = 'Rejouée après résolution du conflit.';

            $this->finish($locked, MovementStatus::Applied, null, $notes, $movement, $actor);
            $this->record($actor, AuditAction::SyncConflictResolved, $locked, [
                'resolution' => 'replayed',
                'applied' => $movement->quantity,
            ]);

            return $locked;
        }, 3);
    }

    // ------------------------------------------------------------------ Ajustements hors ligne

    /** Applique le comptage au stock ACTUEL : l'écart est recalculé au moment de la validation. */
    public function approveAdjustment(SyncOperation $operation, User $actor): SyncOperation
    {
        return DB::transaction(function () use ($operation, $actor) {
            $locked = $this->lock($operation, MovementStatus::PendingValidation);
            $this->assertOwnerIsActive($locked);
            $payload = $locked->payload;

            try {
                $movement = $this->stock->from($locked->uuid, $locked->device_id)->recordAdjustment(
                    $locked->product,
                    (int) $payload['counted_quantity'],
                    $locked->user,
                    $payload['comment'] ?? null,
                    $locked->occurred_at->copy(),
                    isset($payload['lot_id']) ? (int) $payload['lot_id'] : null,
                );
            } catch (StockMovementException $e) {
                throw new SyncResolutionException($e->getMessage());
            }

            $this->finish($locked, MovementStatus::Applied, null, [], $movement, $actor);
            $this->record($actor, AuditAction::SyncAdjustmentValidated, $locked, [
                'decision' => 'approved',
                'counted' => (int) $payload['counted_quantity'],
            ]);

            return $locked;
        }, 3);
    }

    public function refuseAdjustment(SyncOperation $operation, User $actor, string $comment): SyncOperation
    {
        return DB::transaction(function () use ($operation, $actor, $comment) {
            $locked = $this->lock($operation, MovementStatus::PendingValidation);
            $this->assertOwnerIsActive($locked);

            $this->finish($locked, MovementStatus::Rejected, "Refusé par {$actor->name} : {$comment}", [], null, $actor);
            $this->record($actor, AuditAction::SyncAdjustmentValidated, $locked, [
                'decision' => 'refused',
                'counted' => (int) ($locked->payload['counted_quantity'] ?? 0),
                'comment' => $comment,
            ]);

            return $locked;
        }, 3);
    }

    // ------------------------------------------------------------------ Outils

    /** Relit l'opération sous verrou : deux résolutions simultanées ne la traitent jamais deux fois. */
    private function lock(SyncOperation $operation, MovementStatus $expected): SyncOperation
    {
        $locked = SyncOperation::query()->lockForUpdate()->findOrFail($operation->id);

        if ($locked->status !== $expected) {
            throw new SyncResolutionException('Cette opération a déjà été traitée.');
        }

        return $locked->load(['product', 'user', 'device.user']);
    }

    private function finish(SyncOperation $operation, MovementStatus $status, ?string $reason, array $notes, ?StockMovement $movement, User $actor): void
    {
        $operation->forceFill([
            'status' => $status,
            'reason' => $reason,
            'notes' => $notes === [] ? null : $notes,
            'movement_id' => $movement?->id,
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
        ])->save();
    }

    private function record(User $actor, AuditAction $action, SyncOperation $operation, array $details): void
    {
        $this->audit->record($actor, $action, $operation, array_filter([
            'product' => $operation->product?->sku,
            'device' => $operation->device?->name,
        ] + $details, fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Refuse la résolution si le propriétaire du device (ou, à défaut,
     * l'utilisateur de l'opération) est désactivé.
     */
    private function assertOwnerIsActive(SyncOperation $locked): void
    {
        $inactive = collect([$locked->device?->user, $locked->user])
            ->filter()
            ->first(fn (User $u) => ! $u->is_active);

        if ($inactive) {
            throw new SyncResolutionException(
                'Impossible de traiter cette opération : le compte propriétaire est désactivé.'
            );
        }
    }
}