<?php

namespace App\Models;

use App\Enums\AnomalyRule;
use App\Enums\AnomalyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Anomaly extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'rule_code', 'product_id', 'user_id', 'movement_id', 'status', 'comment', 'message',
        'details', 'dedupe_key', 'occurred_at', 'detected_at', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'rule_code' => AnomalyRule::class,
            'status' => AnomalyStatus::class,
            'details' => 'array',
            'occurred_at' => 'datetime',
            'detected_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}