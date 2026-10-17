<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockMovementService
{
    public function __construct(private AuditLogger $audit)
    {
    }
    public const MAX_STOCK = 2_000_000_000;

    public function recordEntry(
        Product $product,
        int $quantity,
        User $user,
        MovementReason $reason = MovementReason::Reception,
        ?string $comment = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        return $this->recordSimple(MovementType::Entry, $product, $quantity, $user, $reason, $comment, $occurredAt);
    }

    public function recordExit(
        Product $product,
        int $quantity,
        User $user,
        MovementReason $reason,
        ?string $comment = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        return $this->recordSimple(MovementType::Exit, $product, $quantity, $user, $reason, $comment, $occurredAt);
    }

    /**
     * Ajustement d'inventaire : on fournit la quantité constatée,
     * le service calcule l'écart par rapport au stock théorique.
     */
    public function recordAdjustment(
        Product $product,
        int $countedQuantity,
        User $user,
        ?string $comment,
        ?CarbonInterface $occurredAt = null,
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
            plan: function (Product $locked) use ($countedQuantity, $comment, $occurredAt) {
                $difference = $countedQuantity - $locked->quantity;

                if ($difference === 0) {
                    throw new StockMovementException(
                        'La quantité constatée est identique au stock théorique : aucun ajustement nécessaire.'
                    );
                }

                return $this->plan(
                    MovementType::Adjustment,
                    MovementReason::Inventory,
                    abs($difference),
                    $countedQuantity,
                    $comment,
                    $occurredAt,
                );
            },
        );

        return $this->refreshProduct($product, $movement);
    }

    /**
     * Correction : enregistre le mouvement inverse d'un mouvement appliqué.
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
                );
            },
        );
    }

    private function recordSimple(
        MovementType $type,
        Product $product,
        int $quantity,
        User $user,
        MovementReason $reason,
        ?string $comment,
        ?CarbonInterface $occurredAt,
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
            plan: function (Product $locked) use ($type, $reason, $quantity, $comment, $occurredAt) {
                $before = $locked->quantity;

                if ($type === MovementType::Exit && $quantity > $before) {
                    throw new InsufficientStockException($before, $quantity);
                }

                $after = $type === MovementType::Entry ? $before + $quantity : $before - $quantity;

                return $this->plan($type, $reason, $quantity, $after, $comment, $occurredAt);
            },
        );

        return $this->refreshProduct($product, $movement);
    }

    /**
     * Cœur du service : une transaction, un verrou de ligne sur le produit,
     * la quantité relue sous verrou, puis mise à jour du stock et du mouvement ensemble.
     */
    private function applyLocked(
        int|string $productId,
        User $user,
        bool $requireActiveProduct,
        Closure $plan,
    ): StockMovement {
        return DB::transaction(function () use ($productId, $user, $requireActiveProduct, $plan) {
            // Verrou de ligne : toute autre transaction qui touche ce produit attend ici.
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

        $this->auditSensitiveOperation($user, $locked, $movement);

        return $movement;
        }, 3);
    }

    /** @return array{type: MovementType, reason: MovementReason, quantity: int, after: int, comment: ?string, occurredAt: ?CarbonInterface, corrects: ?int} */
    private function plan(
        MovementType $type,
        MovementReason $reason,
        int $quantity,
        int $after,
        ?string $comment,
        ?CarbonInterface $occurredAt,
        ?int $corrects = null,
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
        ];
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
}