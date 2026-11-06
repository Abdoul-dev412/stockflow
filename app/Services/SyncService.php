<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Models\Device;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\SyncOperation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class SyncService
{
    public const MAX_BATCH = 100;

    private const FUTURE_TOLERANCE_MINUTES = 5;

    private const PAYLOAD_KEYS = [
        'uuid', 'seq', 'type', 'product_id', 'quantity', 'counted_quantity', 'reason_code',
        'comment', 'lot_number', 'expires_at', 'lot_id', 'occurred_at',
    ];

    public function __construct(private StockMovementService $stock, private AuditLogger $audit)
    {
    }

    /** @return array{code: string, message: string}|null */
    public function blocked(Device $device): ?array
    {
        if ($device->isRevoked()) {
            return ['code' => 'device_revoked', 'message' => 'Appareil révoqué par un administrateur : les opérations sont rejetées.'];
        }

        if (! $device->user->is_active) {
            return ['code' => 'user_inactive', 'message' => 'Compte désactivé : les opérations sont rejetées.'];
        }

        return null;
    }

    /**
     * Traite un lot d'opérations dans l'ordre de leur séquence ; chacune dans sa propre transaction.
     *
     * @param  array<int, mixed>  $operations
     * @return array{results: array<int, array<string, mixed>>, blocked: ?string}
     */
    public function processBatch(Device $device, array $operations): array
    {
        $blocked = $this->blocked($device);

        $results = collect($operations)
            ->map(fn ($operation) => is_array($operation) ? $operation : [])
            ->sortBy(fn (array $operation) => is_numeric($operation['seq'] ?? null) ? (int) $operation['seq'] : PHP_INT_MAX)
            ->values()
            ->map(fn (array $operation) => $this->processOne($device, $operation, $blocked))
            ->all();

        // L'administrateur est informé d'un blocage, une fois par lot de nouvelles opérations rejetées.
        $newlyRejected = collect($results)->where('status', 'rejected')->count();

        if ($blocked !== null && $newlyRejected > 0) {
            $this->audit->record(null, AuditAction::SyncRejected, $device, [
                'device' => $device->name,
                'reason' => $blocked['code'],
                'operations' => $newlyRejected,
            ]);
        }

        return ['results' => $results, 'blocked' => $blocked['code'] ?? null];
    }

    private function processOne(Device $device, array $operation, ?array $blocked): array
    {
        $uuid = isset($operation['uuid']) && is_string($operation['uuid']) && Str::isUuid($operation['uuid'])
            ? strtolower($operation['uuid'])
            : null;
        $seq = isset($operation['seq']) && is_numeric($operation['seq']) ? max(0, (int) $operation['seq']) : null;

        if ($uuid === null) {
            return $this->unsaved(null, $seq, 'rejected', "Identifiant d'opération manquant ou invalide.");
        }

        if ($existing = SyncOperation::query()->where('uuid', $uuid)->first()) {
            return $this->existing($existing, $device, $seq);
        }

        try {
            $record = DB::transaction(function () use ($device, $operation, $uuid, $seq, $blocked) {
                try {
                    $record = SyncOperation::create([
                        'uuid' => $uuid,
                        'device_id' => $device->id,
                        'user_id' => $device->user_id,
                        'seq' => $seq,
                        'type' => is_string($operation['type'] ?? null) ? mb_substr($operation['type'], 0, 20) : null,
                        'payload' => Arr::only($operation, self::PAYLOAD_KEYS),
                        'status' => MovementStatus::Rejected,
                        'received_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Un envoi simultané du même identifiant vient d'être validé : on renverra son résultat.
                    return null;
                }

                $this->resolve($record, $device, $operation, $blocked);

                return $record;
            }, 3);
        } catch (Throwable $e) {
            // Panne inattendue : tout est annulé, rien n'est enregistré, l'appareil pourra réessayer.
            report($e);

            return $this->unsaved($uuid, $seq, 'error', 'Erreur interne : réessaie plus tard.');
        }

        if ($record === null) {
            $existing = SyncOperation::query()->where('uuid', $uuid)->first();

            return $existing
                ? $this->existing($existing, $device, $seq)
                : $this->unsaved($uuid, $seq, 'error', 'Erreur interne : réessaie plus tard.');
        }

        return $this->result($record);
    }

    private function resolve(SyncOperation $record, Device $device, array $operation, ?array $blocked): void
    {
        if ($blocked !== null) {
            $this->finish($record, MovementStatus::Rejected, $blocked['message']);

            return;
        }

        $validator = $this->validator($operation);

        if ($validator->fails()) {
            $fields = implode(', ', array_keys($validator->errors()->toArray()));
            $this->finish($record, MovementStatus::Rejected, "Opération invalide : {$fields}.");

            return;
        }

        $data = $validator->validated();
        $occurredAt = Carbon::parse($data['occurred_at']);

        if ($occurredAt->gt(now()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
            $this->finish($record, MovementStatus::Rejected, "La date de l'opération est dans le futur : vérifie l'horloge de l'appareil.");

            return;
        }

        $product = Product::query()->find($data['product_id']);

        if ($product === null) {
            $this->finish($record, MovementStatus::Rejected, 'Produit introuvable.');

            return;
        }

        $record->forceFill(['product_id' => $product->id, 'occurred_at' => $occurredAt]);

        if ($data['type'] === 'adjustment') {
            if ($product->tracks_lots && empty($data['lot_id'])) {
                $this->finish($record, MovementStatus::Rejected,
                    "Pour un produit suivi par lots, l'inventaire se fait lot par lot : indique le lot compté.");

                return;
            }

            // Jamais appliqué automatiquement : le stock a pu évoluer pendant que l'appareil était hors ligne.
            $this->finish($record, MovementStatus::PendingValidation,
                'Inventaire saisi hors ligne : en attente de validation par un administrateur.');

            return;
        }

        $user = $device->user;
        $service = $this->stock->from($record->uuid, $device->id);
        $notes = [];

        try {
            $movement = $data['type'] === 'entry'
                ? $service->recordEntry(
                    $product,
                    (int) $data['quantity'],
                    $user,
                    MovementReason::from($data['reason_code']),
                    $data['comment'] ?? null,
                    $occurredAt,
                    $data['lot_number'] ?? null,
                    isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                )
                : $this->exit($service, $product, $data, $user, $occurredAt, $notes);

            $this->finish($record, MovementStatus::Applied, null, $notes, $movement);
        } catch (InsufficientStockException $e) {
            $this->finish($record, MovementStatus::Conflict, $e->getMessage(), $notes);
        } catch (StockMovementException $e) {
            $this->finish($record, MovementStatus::Rejected, $e->getMessage(), $notes);
        }
    }

    /**
     * Sortie. Si l'appareil désigne un lot devenu indisponible, le serveur recalcule la répartition FEFO
     * et le signale ; l'appareil n'a de toute façon qu'une vue estimée des lots.
     */
    private function exit(StockMovementService $service, Product $product, array $data, User $user, Carbon $at, array &$notes): StockMovement
    {
        $reason = MovementReason::from($data['reason_code']);
        $lotId = $product->tracks_lots ? ($data['lot_id'] ?? null) : null;

        try {
            return $service->recordExit($product, (int) $data['quantity'], $user, $reason, $data['comment'] ?? null, $at, $lotId);
        } catch (StockMovementException $e) {
            if ($lotId === null) {
                throw $e;
            }

            $notes[] = 'Lot demandé indisponible : répartition FEFO recalculée par le serveur.';

            return $service->recordExit($product, (int) $data['quantity'], $user, $reason, $data['comment'] ?? null, $at, null);
        }
    }

    private function validator(array $operation): ValidatorContract
    {
        $type = $operation['type'] ?? null;
        $max = StockMovementService::MAX_STOCK;
        $isStockOperation = in_array($type, ['entry', 'exit'], true);
        $isAdjustment = $type === 'adjustment';

        $reasons = is_string($type) && MovementType::tryFrom($type) !== null
            ? array_map(fn (MovementReason $reason) => $reason->value, MovementType::from($type)->allowedReasons())
            : [];

        return Validator::make($operation, [
            'type' => ['required', Rule::in(['entry', 'exit', 'adjustment'])],
            'product_id' => ['required', 'integer', 'min:1'],
            'occurred_at' => ['required', 'date'],
            'comment' => [$isAdjustment ? 'required' : 'nullable', 'string', 'max:500'],
            'quantity' => $isStockOperation ? ['required', 'integer', 'min:1', "max:{$max}"] : ['nullable'],
            'counted_quantity' => $isAdjustment ? ['required', 'integer', 'min:0', "max:{$max}"] : ['nullable'],
            'reason_code' => [$isAdjustment ? 'nullable' : 'required', Rule::in($reasons)],
            'lot_number' => ['nullable', 'string', 'max:64'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'lot_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function finish(SyncOperation $record, MovementStatus $status, ?string $reason = null, array $notes = [], ?StockMovement $movement = null): void
    {
        $record->forceFill([
            'status' => $status,
            'reason' => $reason,
            'notes' => $notes === [] ? null : $notes,
            'movement_id' => $movement?->id,
        ])->save();
    }

    private function existing(SyncOperation $record, Device $device, ?int $seq): array
    {
        // Un appareil ne reçoit jamais le résultat de l'opération d'un autre appareil.
        return $record->device_id === $device->id
            ? $this->result($record, duplicate: true)
            : $this->unsaved($record->uuid, $seq, 'rejected', "Identifiant d'opération déjà utilisé.");
    }

    private function result(SyncOperation $record, bool $duplicate = false): array
    {
        $record->loadMissing('movement');

        $result = [
            'uuid' => $record->uuid,
            'seq' => $record->seq,
            'status' => $duplicate ? 'duplicate' : $record->status->value,
            'reason' => $record->reason,
            'notes' => $record->notes ?? [],
            'movement_id' => $record->movement_id,
            'quantity_after' => $record->movement?->quantity_after,
        ];

        if ($duplicate) {
            $result['initial_status'] = $record->status->value;
        }

        return $result;
    }

    private function unsaved(?string $uuid, ?int $seq, string $status, string $reason): array
    {
        return [
            'uuid' => $uuid,
            'seq' => $seq,
            'status' => $status,
            'reason' => $reason,
            'notes' => [],
            'movement_id' => null,
            'quantity_after' => null,
        ];
    }
}