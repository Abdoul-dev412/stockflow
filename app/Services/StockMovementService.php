<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Models\MovementLotAllocation;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class StockMovementService
{
    public const MAX_STOCK = 2_000_000_000;

    public function __construct(private AuditLogger $audit)
    {
    }

    public function recordEntry(
        Product $product,
        int $quantity,
        User $user,
        MovementReason $reason = MovementReason::Reception,
        ?string $comment = null,
        ?CarbonInterface $occurredAt = null,
        ?string $lotNumber = null,
        ?CarbonInterface $expiresAt = null,
    ): StockMovement {
        return $this->recordSimple(
            MovementType::Entry, $product, $quantity, $user, $reason, $comment, $occurredAt,
            lotNumber: $lotNumber, expiresAt: $expiresAt,
        );
    }

    public function recordExit(
        Product $product,
        int $quantity,
        User $user,
        MovementReason $reason,
        ?string $comment = null,
        ?CarbonInterface $occurredAt = null,
        ?int $lotId = null,
    ): StockMovement {
        return $this->recordSimple(
            MovementType::Exit, $product, $quantity, $user, $reason, $comment, $occurredAt,
            lotId: $lotId,
        );
    }

    /**
     * Ajustement d'inventaire : on fournit la quantité constatée, le service calcule l'écart.
     * Pour un produit suivi par lots, la quantité constatée est celle du lot compté.
     */
    public function recordAdjustment(
        Product $product,
        int $countedQuantity,
        User $user,
        ?string $comment,
        ?CarbonInterface $occurredAt = null,
        ?int $lotId = null,
    ): StockMovement {
        $comment = $this->cleanComment($comment);

        $this->assertActiveUser($user);

        if ($countedQuantity < 0 || $countedQuantity > self::MAX_STOCK) {
            throw new StockMovementException('La quantité constatée doit être un entier positif ou nul.');
        }

        $this->assertComment($comment, 'Un ajustement doit être justifié par un commentaire.');

        $movement = $this->applyLocked(
            $product->getKey(),
            $user,
            requireActiveProduct: true,
            plan: function (Product $locked) use ($countedQuantity, $comment, $occurredAt, $lotId) {
                $effects = [];

                if ($locked->tracks_lots) {
                    $effects = $this->planLotAdjustment($locked, $countedQuantity, $lotId);
                    $difference = $effects[0]['delta'];
                } else {
                    if ($lotId !== null) {
                        throw new StockMovementException("Ce produit n'est pas suivi par lots.");
                    }

                    $difference = $countedQuantity - $locked->quantity;

                    if ($difference === 0) {
                        throw new StockMovementException(
                            'La quantité constatée est identique au stock théorique : aucun ajustement nécessaire.'
                        );
                    }
                }

                return $this->plan(
                    MovementType::Adjustment,
                    MovementReason::Inventory,
                    abs($difference),
                    $locked->quantity + $difference,
                    $comment,
                    $occurredAt,
                    lotEffects: $effects,
                );
            },
        );

        return $this->refreshProduct($product, $movement);
    }

    /**
     * Correction : enregistre le mouvement inverse d'un mouvement appliqué (lot par lot si besoin).
     * L'original n'est jamais modifié.
     */
    public function recordCorrection(
        StockMovement $original,
        User $user,
        ?string $comment,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        $comment = $this->cleanComment($comment);

        $this->assertActiveUser($user);
        $this->assertComment($comment, 'Une correction doit être justifiée par un commentaire.');

        return $this->applyLocked(
            $original->product_id,
            $user,
            requireActiveProduct: false,
            plan: function (Product $locked) use ($original, $comment, $occurredAt) {
                // On relit l'original sous verrou : son état a pu changer entre-temps.
                $fresh = StockMovement::query()->find($original->getKey());

                if ($fresh === null || $fresh->status !== MovementStatus::Applied) {
                    throw new StockMovementException('Seul un mouvement appliqué peut être corrigé.');
                }

                if ($fresh->type === MovementType::Correction) {
                    throw new StockMovementException(
                        'Une correction ne peut pas être corrigée : enregistre une nouvelle opération.'
                    );
                }

                if (StockMovement::query()->where('corrects_movement_id', $fresh->id)->exists()) {
                    throw new StockMovementException('Ce mouvement a déjà été corrigé.');
                }

                $before = $locked->quantity;
                $after = $before - $fresh->signedQuantity();
                $effects = [];

                if ($locked->tracks_lots) {
                    $effects = $this->planLotCorrection($fresh);
                }

                if ($after < 0) {
                    throw new InsufficientStockException($before, $fresh->quantity);
                }

                return $this->plan(
                    MovementType::Correction,
                    MovementReason::Correction,
                    $fresh->quantity,
                    $after,
                    $comment,
                    $occurredAt,
                    $fresh->id,
                    $effects,
                );
            },
        );
    }

        /**
     * Retire d'un coup tous les lots expirés d'un produit : un seul mouvement de sortie
     * (motif « Péremption »), réparti sur chaque lot expiré.
     */
    public function removeExpiredLots(Product $product, User $user, ?CarbonInterface $occurredAt = null): StockMovement
    {
        $this->assertActiveUser($user);

        $movement = $this->applyLocked(
            $product->getKey(),
            $user,
            requireActiveProduct: true,
            plan: function (Product $locked) use ($occurredAt) {
                if (! $locked->tracks_lots) {
                    throw new StockMovementException("Ce produit n'est pas suivi par lots.");
                }

                $expired = StockLot::query()
                    ->where('product_id', $locked->id)
                    ->inStock()
                    ->fefo()
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn (StockLot $lot) => $lot->isExpired());

                if ($expired->isEmpty()) {
                    throw new StockMovementException('Aucun lot expiré à retirer pour ce produit.');
                }

                $total = (int) $expired->sum('quantity_remaining');

                $effects = $expired
                    ->map(fn (StockLot $lot) => ['lot' => $lot, 'new' => null, 'delta' => -$lot->quantity_remaining])
                    ->values()
                    ->all();

                return $this->plan(
                    MovementType::Exit,
                    MovementReason::Expiry,
                    $total,
                    $locked->quantity - $total,
                    'Retrait des lots expirés',
                    $occurredAt,
                    lotEffects: $effects,
                );
            },
        );

        return $this->refreshProduct($product, $movement);
}

    private function recordSimple(
        MovementType $type,
        Product $product,
        int $quantity,
        User $user,
        MovementReason $reason,
        ?string $comment,
        ?CarbonInterface $occurredAt,
        ?string $lotNumber = null,
        ?CarbonInterface $expiresAt = null,
        ?int $lotId = null,
    ): StockMovement {
        $comment = $this->cleanComment($comment);

        $this->assertActiveUser($user);

        if ($quantity < 1 || $quantity > self::MAX_STOCK) {
            throw new StockMovementException('La quantité doit être un entier strictement positif.');
        }

        if (! in_array($reason, $type->allowedReasons(), true)) {
            throw new StockMovementException(
                "Le motif « {$reason->label()} » n'est pas valide pour une opération de type « {$type->label()} »."
            );
        }

        $this->assertComment(
            $comment,
            $reason === MovementReason::Other ? 'Un commentaire est obligatoire pour le motif « Autre ».' : null,
        );

        $movement = $this->applyLocked(
            $product->getKey(),
            $user,
            requireActiveProduct: true,
            plan: function (Product $locked) use ($type, $reason, $quantity, $comment, $occurredAt, $lotNumber, $expiresAt, $lotId) {
                $before = $locked->quantity;
                $effects = [];

                if ($locked->tracks_lots) {
                    $effects = $type === MovementType::Entry
                        ? $this->planLotEntry($locked, $lotNumber, $expiresAt, $quantity)
                        : $this->planLotExit($locked, $quantity, $reason, $lotId);
                } else {
                    if ($lotNumber !== null || $expiresAt !== null || $lotId !== null) {
                        throw new StockMovementException("Ce produit n'est pas suivi par lots.");
                    }

                    if ($type === MovementType::Exit && $quantity > $before) {
                        throw new InsufficientStockException($before, $quantity);
                    }
                }

                $after = $type === MovementType::Entry ? $before + $quantity : $before - $quantity;

                return $this->plan($type, $reason, $quantity, $after, $comment, $occurredAt, lotEffects: $effects);
            },
        );

        return $this->refreshProduct($product, $movement);
    }

    // ------------------------------------------------------------------ Lots

    /** @return array<int, array{lot: ?StockLot, new: ?array, delta: int}> */
    private function planLotEntry(Product $product, ?string $lotNumber, ?CarbonInterface $expiresAt, int $quantity): array
    {
        $number = $lotNumber === null ? '' : mb_strtoupper(trim($lotNumber));

        if ($number === '' || mb_strlen($number) > 64) {
            throw new StockMovementException(
                'Le numéro de lot est obligatoire pour ce produit (64 caractères au maximum).'
            );
        }

        $expiry = $expiresAt?->copy()->startOfDay();

        if ($product->is_perishable && $expiry === null) {
            throw new StockMovementException('La date de péremption est obligatoire pour ce produit périssable.');
        }

        if ($expiry !== null && $expiry->lt(today())) {
            throw new StockMovementException(
                'La date de péremption est dépassée : une entrée de stock périmé ne peut pas être enregistrée.'
            );
        }

        $existing = StockLot::query()
            ->where('product_id', $product->id)
            ->where('lot_number', $number)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            return [[
                'lot' => null,
                'new' => [
                    'product_id' => $product->id,
                    'lot_number' => $number,
                    'expires_at' => $expiry?->toDateString(),
                    'unit_cost' => $product->purchase_price,
                ],
                'delta' => $quantity,
            ]];
        }

        $sameExpiry = $existing->expires_at === null
            ? $expiry === null
            : ($expiry !== null && $existing->expires_at->isSameDay($expiry));

        if (! $sameExpiry) {
            throw new StockMovementException("Le lot {$number} existe déjà avec une autre date de péremption.");
        }

        return [['lot' => $existing, 'new' => null, 'delta' => $quantity]];
    }

    /** @return array<int, array{lot: ?StockLot, new: ?array, delta: int}> */
    private function planLotExit(Product $product, int $quantity, MovementReason $reason, ?int $lotId): array
    {
        $isExpiryRemoval = $reason === MovementReason::Expiry;

        if ($lotId !== null) {
            $lot = $this->findLot($product, $lotId);

            if ($isExpiryRemoval && ! $lot->isExpired()) {
                throw new StockMovementException("Le lot {$lot->lot_number} n'est pas expiré.");
            }

            if (! $isExpiryRemoval && $lot->isExpired()) {
                throw new StockMovementException(
                    "Le lot {$lot->lot_number} est expiré : utilise le motif « Péremption » pour le retirer du stock."
                );
            }

            if ($quantity > $lot->quantity_remaining) {
                throw new InsufficientStockException($lot->quantity_remaining, $quantity, "lot {$lot->lot_number}");
            }

            return [['lot' => $lot, 'new' => null, 'delta' => -$quantity]];
        }

        $lots = StockLot::query()
            ->where('product_id', $product->id)
            ->inStock()
            ->fefo()
            ->lockForUpdate()
            ->get();

        $expiredQuantity = $lots->filter(fn (StockLot $lot) => $lot->isExpired())->sum('quantity_remaining');

        $pool = $lots->filter(fn (StockLot $lot) => $isExpiryRemoval ? $lot->isExpired() : ! $lot->isExpired());
        $available = (int) $pool->sum('quantity_remaining');

        if ($quantity > $available) {
            $detail = ! $isExpiryRemoval && $expiredQuantity > 0
                ? "{$expiredQuantity} unité(s) en lots expirés, retirables seulement avec le motif « Péremption »"
                : null;

            throw new InsufficientStockException($available, $quantity, $detail);
        }

        $effects = [];
        $needed = $quantity;

        foreach ($pool as $lot) {
            if ($needed === 0) {
                break;
            }

            $take = min($needed, $lot->quantity_remaining);
            $effects[] = ['lot' => $lot, 'new' => null, 'delta' => -$take];
            $needed -= $take;
        }

        return $effects;
    }

    /** @return array<int, array{lot: ?StockLot, new: ?array, delta: int}> */
    private function planLotAdjustment(Product $product, int $countedQuantity, ?int $lotId): array
    {
        if ($lotId === null) {
            throw new StockMovementException(
                "Pour un produit suivi par lots, l'inventaire se fait lot par lot : indique le lot compté."
            );
        }

        $lot = $this->findLot($product, $lotId);
        $difference = $countedQuantity - $lot->quantity_remaining;

        if ($difference === 0) {
            throw new StockMovementException(
                'La quantité constatée est identique au stock théorique du lot : aucun ajustement nécessaire.'
            );
        }

        return [['lot' => $lot, 'new' => null, 'delta' => $difference]];
    }

    /** @return array<int, array{lot: ?StockLot, new: ?array, delta: int}> */
    private function planLotCorrection(StockMovement $original): array
    {
        $allocations = MovementLotAllocation::query()->where('movement_id', $original->id)->get();

        if ($allocations->isEmpty()) {
            throw new StockMovementException('Ce mouvement ne comporte aucune répartition par lot : correction impossible.');
        }

        $lots = StockLot::query()
            ->whereIn('id', $allocations->pluck('lot_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $effects = [];

        foreach ($allocations as $allocation) {
            $lot = $lots[$allocation->lot_id];
            $delta = -$allocation->quantity;

            if ($lot->quantity_remaining + $delta < 0) {
                throw new StockMovementException(
                    "Le lot {$lot->lot_number} ne contient plus assez d'unités pour annuler ce mouvement."
                );
            }

            $effects[] = ['lot' => $lot, 'new' => null, 'delta' => $delta];
        }

        return $effects;
    }

    private function findLot(Product $product, int $lotId): StockLot
    {
        return StockLot::query()
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->find($lotId)
            ?? throw new StockMovementException('Ce lot est introuvable pour ce produit.');
    }

    /** @param array<int, array{lot: ?StockLot, new: ?array, delta: int}> $effects */
    private function applyLotEffects(StockMovement $movement, Product $product, array $effects): void
    {
        foreach ($effects as $effect) {
            if ($effect['lot'] === null) {
                $lot = StockLot::create($effect['new'] + [
                    'quantity_remaining' => $effect['delta'],
                    'received_at' => $movement->occurred_at,
                ]);
            } else {
                $lot = $effect['lot'];
                $lot->forceFill(['quantity_remaining' => $lot->quantity_remaining + $effect['delta']])->save();
            }

            MovementLotAllocation::create([
                'movement_id' => $movement->id,
                'lot_id' => $lot->id,
                'quantity' => $effect['delta'],
            ]);
        }

        if ($product->tracks_lots) {
            // Filet de sécurité : si l'invariant est rompu, la transaction entière est annulée.
            $sum = (int) StockLot::query()->where('product_id', $product->id)->sum('quantity_remaining');

            if ($sum !== $movement->quantity_after) {
                throw new LogicException(
                    "Invariant rompu : la somme des lots ({$sum}) diffère du stock ({$movement->quantity_after})."
                );
            }
        }
    }

    // ------------------------------------------------------------------ Noyau commun

    /**
     * Une transaction, un verrou de ligne sur le produit, la quantité relue sous verrou,
     * puis mise à jour du stock, du mouvement et des lots ensemble.
     */
    private function applyLocked(
        int|string $productId,
        User $user,
        bool $requireActiveProduct,
        Closure $plan,
    ): StockMovement {
        return DB::transaction(function () use ($productId, $user, $requireActiveProduct, $plan) {
            // Verrou de ligne : toute autre transaction qui touche ce produit (et ses lots) attend ici.
            $locked = Product::query()->lockForUpdate()->find($productId);

            if ($locked === null) {
                throw new StockMovementException('Produit introuvable.');
            }

            if ($requireActiveProduct && ! $locked->is_active) {
                throw new StockMovementException(
                    'Un produit inactif ne peut plus faire l\'objet de nouvelles opérations de stock.'
                );
            }

            $plan = $plan($locked);
            $before = $locked->quantity;

            $locked->forceFill(['quantity' => $plan['after']])->save();

            $movement = StockMovement::create([
                'uuid' => (string) Str::uuid(),
                'product_id' => $locked->id,
                'user_id' => $user->getKey(),
                'type' => $plan['type'],
                'reason_code' => $plan['reason'],
                'quantity' => $plan['quantity'],
                'quantity_before' => $before,
                'quantity_after' => $plan['after'],
                'reason' => $plan['comment'],
                'corrects_movement_id' => $plan['corrects'],
                'status' => MovementStatus::Applied,
                'occurred_at' => $plan['occurredAt'] ?? now(),
                'recorded_at' => now(),
            ]);

            $this->applyLotEffects($movement, $locked, $plan['lotEffects']);
            $this->auditSensitiveOperation($user, $locked, $movement);

            return $movement;
        }, 3);
    }

    /** @return array{type: MovementType, reason: MovementReason, quantity: int, after: int, comment: ?string, occurredAt: ?CarbonInterface, corrects: ?int, lotEffects: array} */
    private function plan(
        MovementType $type,
        MovementReason $reason,
        int $quantity,
        int $after,
        ?string $comment,
        ?CarbonInterface $occurredAt,
        ?int $corrects = null,
        array $lotEffects = [],
    ): array {
        if ($after > self::MAX_STOCK) {
            throw new StockMovementException('La quantité en stock dépasserait la limite autorisée.');
        }

        return [
            'type' => $type,
            'reason' => $reason,
            'quantity' => $quantity,
            'after' => $after,
            'comment' => $comment,
            'occurredAt' => $occurredAt,
            'corrects' => $corrects,
            'lotEffects' => $lotEffects,
        ];
    }

    /** Ajustements et corrections sont tracés, dans la même transaction que le mouvement. */
    private function auditSensitiveOperation(User $user, Product $product, StockMovement $movement): void
    {
        $action = match ($movement->type) {
            MovementType::Adjustment => AuditAction::StockAdjustment,
            MovementType::Correction => AuditAction::StockCorrection,
            default => null,
        };

        if ($action === null) {
            return;
        }

        $this->audit->record($user, $action, $movement, array_filter([
            'product' => $product->sku,
            'quantity_before' => $movement->quantity_before,
            'quantity_after' => $movement->quantity_after,
            'reason' => $movement->reason,
            'corrects_movement_id' => $movement->corrects_movement_id,
        ], fn ($value) => $value !== null));
    }

    /** Garde le modèle de l'appelant cohérent avec la base. */
    private function refreshProduct(Product $product, StockMovement $movement): StockMovement
    {
        $product->forceFill(['quantity' => $movement->quantity_after])
            ->syncOriginalAttribute('quantity');

        return $movement;
    }

    private function cleanComment(?string $comment): ?string
    {
        return blank($comment) ? null : trim($comment);
    }

    private function assertActiveUser(User $user): void
    {
        if (! $user->is_active) {
            throw new StockMovementException('Un compte désactivé ne peut pas enregistrer de mouvement.');
        }
    }

    private function assertComment(?string $comment, ?string $missingMessage): void
    {
        if ($comment === null && $missingMessage !== null) {
            throw new StockMovementException($missingMessage);
        }

        if ($comment !== null && mb_strlen($comment) > 500) {
            throw new StockMovementException('Le commentaire ne doit pas dépasser 500 caractères.');
        }
    }
}