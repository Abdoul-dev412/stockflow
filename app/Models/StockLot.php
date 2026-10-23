<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLot extends Model
{
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