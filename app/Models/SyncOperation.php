<?php

namespace App\Models;

use App\Enums\MovementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncOperation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid', 'device_id', 'user_id', 'seq', 'type', 'product_id', 'payload', 'occurred_at',
        'status', 'reason', 'notes', 'movement_id', 'received_at', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'notes' => 'array',
            'status' => MovementStatus::class,
            'seq' => 'integer',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }
}