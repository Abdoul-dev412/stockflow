<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrityCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['type', 'status', 'issues_count', 'signature', 'details', 'checked_at'];

    protected function casts(): array
    {
        return [
            'issues_count' => 'integer',
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /** Dernière vérification d'un type (« stock » ou « audit »). */
    public static function lastOf(string $type): ?self
    {
        return static::query()
            ->where('type', $type)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();
    }
}