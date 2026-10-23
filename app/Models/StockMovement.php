<?php

namespace App\Models;

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_movement_id');
    }

    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->when($filters['product'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($search !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p->withTrashed()
                ->where(fn ($p) => $p->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%"))))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('occurred_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('occurred_at', '<=', Carbon::parse($date)->endOfDay()));
    }
    public function allocations(): HasMany
    {
        return $this->hasMany(MovementLotAllocation::class, 'movement_id');
    }
}