<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLot extends Model
{
    public const EXPIRY_WARNING_DAYS = 15;

    protected $fillable = [
        'product_id', 'lot_number', 'expires_at', 'quantity_remaining', 'received_at', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date:Y-m-d',
            'received_at' => 'datetime',
            'quantity_remaining' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(MovementLotAllocation::class, 'lot_id');
    }

    /** Un lot expire à la fin de sa date : il est expiré dès le lendemain. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }

    public function daysLeft(): ?int
    {
        return $this->expires_at === null
            ? null
            : (int) round(today()->diffInDays($this->expires_at, false));
    }

    public function expiryLabel(): string
    {
        $days = $this->daysLeft();

        return match (true) {
            $days === null => 'Sans date de péremption',
            $days < 0 => 'Expiré depuis '.abs($days).' j',
            $days === 0 => "Expire aujourd'hui",
            default => "Expire dans {$days} j",
        };
    }

    public function scopeOfActiveProducts(Builder $query): Builder
    {
        return $query->whereHas('product', fn ($product) => $product->active());
    }

    /**
     * Compteurs des lots non vides de produits actifs, en une seule requête.
     *
     * @return array{expired: int, expired_units: int, soon: int}
     */
    public static function expiryCounts(int $days = self::EXPIRY_WARNING_DAYS): array
    {
        $today = today()->toDateString();
        $limit = today()->addDays($days)->toDateString();

        $row = static::query()
            ->inStock()
            ->whereNotNull('expires_at')
            ->ofActiveProducts()
            ->selectRaw(
                'COALESCE(SUM(expires_at < ?), 0) as expired_count,
                COALESCE(SUM(CASE WHEN expires_at < ? THEN quantity_remaining ELSE 0 END), 0) as expired_units,
                COALESCE(SUM(expires_at >= ? AND expires_at <= ?), 0) as soon_count',
                [$today, $today, $today, $limit],
            )
            ->first();

        return [
            'expired' => (int) $row->expired_count,
            'expired_units' => (int) $row->expired_units,
            'soon' => (int) $row->soon_count,
        ];
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('quantity_remaining', '>', 0);
    }

    /** First Expired, First Out : péremption la plus proche, lots sans date en dernier, puis réception. */
    public function scopeFefo(Builder $query): Builder
    {
        return $query
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('received_at')
            ->orderBy('id');
    }
}