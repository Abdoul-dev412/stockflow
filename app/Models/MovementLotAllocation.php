<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MovementLotAllocation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['movement_id', 'lot_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une répartition par lot est immuable.'));
        static::deleting(fn () => throw new LogicException('Une répartition par lot ne peut pas être supprimée.'));
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}