<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockMovementService
{
    public const MAX_STOCK = 2_000_000_000;

    public function recordEntry(
        Product $product,
        int $quantity,
        User $user,
        MovementReason $reason = MovementReason::Reception,
        ?string $comment = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        return $this->record(MovementType::Entry, $product, $quantity, $user, $reason, $comment, $occurredAt);
    }

    public function recordExit(
        Product $product,
        int $quantity,
        User $user,
        MovementReason $reason,
        ?string $comment = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        return $this->record(MovementType::Exit, $product, $quantity, $user, $reason, $comment, $occurredAt);
    }

    private function record(
        MovementType $type,
        Product $product,
        int $quantity,
        User $user,
        MovementReason $reason,
        ?string $comment,
        ?CarbonInterface $occurredAt,
    ): StockMovement {
        $comment = blank($comment) ? null : trim($comment);

        $this->assertValidRequest($type, $quantity, $user, $reason, $comment);

        $movement = DB::transaction(function () use ($type, $product, $quantity, $user, $reason, $comment, $occurredAt) {
            // Verrou de ligne : toute autre transaction qui touche ce produit attend ici.
            $locked = Product::query()->lockForUpdate()->find($product->getKey());

            if ($locked === null) {
                throw new StockMovementException('Produit introuvable.');
            }

            if (! $locked->is_active) {
                throw new StockMovementException(
                    'Un produit inactif ne peut plus faire l\'objet de nouvelles opérations de stock.'
                );
            }

            $before = $locked->quantity;

            if ($type === MovementType::Exit && $quantity > $before) {
                throw new InsufficientStockException($before, $quantity);
            }

            $after = $type === MovementType::Entry ? $before + $quantity : $before - $quantity;

            if ($after > self::MAX_STOCK) {
                throw new StockMovementException('La quantité en stock dépasserait la limite autorisée.');
            }

            $locked->forceFill(['quantity' => $after])->save();

            return StockMovement::create([
                'uuid' => (string) Str::uuid(),
                'product_id' => $locked->id,
                'user_id' => $user->getKey(),
                'type' => $type,
                'reason_code' => $reason,
                'quantity' => $quantity,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => $comment,
                'status' => MovementStatus::Applied,
                'occurred_at' => $occurredAt ?? now(),
                'recorded_at' => now(),
            ]);
        }, 3);

        // Garde le modèle de l'appelant cohérent avec la base.
        $product->forceFill(['quantity' => $movement->quantity_after])
            ->syncOriginalAttribute('quantity');

        return $movement;
    }

    private function assertValidRequest(
        MovementType $type,
        int $quantity,
        User $user,
        MovementReason $reason,
        ?string $comment,
    ): void {
        if (! in_array($type, [MovementType::Entry, MovementType::Exit], true)) {
            throw new StockMovementException('Ce type de mouvement n\'est pas géré ici.');
        }

        if ($quantity < 1 || $quantity > self::MAX_STOCK) {
            throw new StockMovementException('La quantité doit être un entier strictement positif.');
        }

        if (! in_array($reason, $type->allowedReasons(), true)) {
            throw new StockMovementException(
                "Le motif « {$reason->label()} » n'est pas valide pour une opération de type « {$type->label()} »."
            );
        }

        if ($reason === MovementReason::Other && $comment === null) {
            throw new StockMovementException('Un commentaire est obligatoire pour le motif « Autre ».');
        }

        if ($comment !== null && mb_strlen($comment) > 500) {
            throw new StockMovementException('Le commentaire ne doit pas dépasser 500 caractères.');
        }

        if (! $user->is_active) {
            throw new StockMovementException('Un compte désactivé ne peut pas enregistrer de mouvement.');
        }
    }
}