<?php

namespace App\Models;

use App\Enums\StockStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory , SoftDeletes;
     // `quantity` est volontairement absent : seul le service de mouvements
    // de stock (jour 6) pourra la modifier.
    protected $fillable = [
        'sku', 'barcode', 'name', 'description',
        'category_id', 'supplier_id', 'unit',
        'purchase_price', 'sale_price', 'minimum_stock',
        'tracks_lots', 'target_cover_days', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'quantity' => 'integer',
            'minimum_stock' => 'integer',
            'tracks_lots' => 'boolean',
            'target_cover_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stockStatus(): StockStatus
    {
        if ($this->quantity === 0) {
            return StockStatus::OutOfStock;
        }

        return $this->quantity <= $this->minimum_stock
            ? StockStatus::Low
            : StockStatus::Normal;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0)
            ->whereColumn('quantity', '<=', 'minimum_stock');
    }

    public function scopeOutOfStock(Builder $query): Builder
    {
        return $query->where('quantity', 0);
    }
    public function scopeNormalStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0)
            ->whereColumn('quantity', '>', 'minimum_stock');
    }
    // stock movements relationship
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
