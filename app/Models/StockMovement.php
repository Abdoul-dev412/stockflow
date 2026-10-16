<?php

namespace App\Models;

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid', 'product_id', 'user_id', 'type', 'reason_code',
        'quantity', 'quantity_before', 'quantity_after', 'reason',
        'corrects_movement_id', 'status', 'occurred_at', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'reason_code' => MovementReason::class,
            'status' => MovementStatus::class,
            'quantity' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $movement) {
            if ($movement->getRawOriginal('status') === MovementStatus::Applied->value) {
                throw new LogicException(
                    'Un mouvement appliqué est immuable : enregistre une correction.'
                );
            }
        });

        static::deleting(function (self $movement) {
            if ($movement->getRawOriginal('status') === MovementStatus::Applied->value) {
                throw new LogicException(
                    'Un mouvement appliqué ne peut pas être supprimé : enregistre une correction.'
                );
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Variation réelle du stock : positive pour un apport, négative pour un retrait. */
    public function signedQuantity(): int
    {
        return ($this->quantity_after ?? 0) - ($this->quantity_before ?? 0);
    }
}