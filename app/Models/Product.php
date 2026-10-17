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
    /** Produits à surveiller : stock faible ou rupture (la quantité 0 est toujours <= au seuil). */
    public function scopeNeedsRestock(Builder $query): Builder
    {
        return $query->whereColumn('quantity', '<=', 'minimum_stock');
    }

    /** Ruptures d'abord, puis les stocks les plus bas. */
    public function scopeMostUrgentFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN quantity = 0 THEN 0 ELSE 1 END')
            ->orderBy('quantity')
            ->orderBy('name');
    }

    /** @return array{active: int, low: int, out: int} compteurs des produits actifs, en une requête */
    public static function stockCounts(): array
    {
        $row = static::query()
            ->active()
            ->selectRaw('COUNT(*) as active_count,
                COALESCE(SUM(quantity = 0), 0) as out_count,
                COALESCE(SUM(quantity > 0 AND quantity <= minimum_stock), 0) as low_count')
            ->first();

        return [
            'active' => (int) $row->active_count,
            'low' => (int) $row->low_count,
            'out' => (int) $row->out_count,
        ];
    }

    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? null;
        $stock = $filters['stock'] ?? null;

        return $query
            ->when($search !== '', fn ($q) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
            ))
            ->when($filters['category'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($stock === 'normal', fn ($q) => $q->normalStock())
            ->when($stock === 'low', fn ($q) => $q->lowStock())
            ->when($stock === 'out', fn ($q) => $q->outOfStock());
    }
}
