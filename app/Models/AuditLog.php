<?php

namespace App\Models;

use App\Enums\AuditAction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'entity_type', 'entity_id', 'details'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'entity_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException("Le journal d'audit est en lecture seule."));
        static::deleting(fn () => throw new LogicException("Le journal d'audit ne peut pas être supprimé."));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return AuditAction::tryFrom($this->action)?->label() ?? $this->action;
    }

    public function entityLabel(): string
    {
        return match ($this->entity_type) {
            'product' => 'Produit',
            'category' => 'Catégorie',
            'supplier' => 'Fournisseur',
            'stock_movement' => 'Mouvement',
            'export' => 'Export',
            'user' => 'Utilisateur',
            default => $this->entity_type,
        };
    }

    /** @return array<int, string> lignes lisibles pour l'écran d'audit */
    public function detailLines(): array
    {
        $lines = [];

        foreach ($this->details ?? [] as $key => $value) {
            if ($key === 'changes' && is_array($value)) {
                foreach ($value as $field => $change) {
                    $lines[] = "{$field} : ".self::format($change['from'] ?? null).' → '.self::format($change['to'] ?? null);
                }
            } elseif ($key === 'snapshot' && is_array($value)) {
                foreach ($value as $field => $fieldValue) {
                    $lines[] = "{$field} : ".self::format($fieldValue);
                }
            } else {
                $lines[] = "{$key} : ".self::format($value);
            }
        }

        return $lines;
    }

    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('created_at', '<=', Carbon::parse($date)->endOfDay()));
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'oui' : 'non',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }
}