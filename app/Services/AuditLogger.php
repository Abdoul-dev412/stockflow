<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    /** @param Model|string $entity un modèle, ou un type libre (« export ») sans identifiant */
    public function record(?User $actor, AuditAction $action, Model|string $entity, array $details = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => $action->value,
            'entity_type' => is_string($entity) ? $entity : Str::snake(class_basename($entity)),
            'entity_id' => is_string($entity) ? null : $entity->getKey(),
            'details' => $details === [] ? null : $details,
        ]);
    }

    /** Enregistre un nouvel élément et sa trace d'audit dans la même transaction. */
    public function createWithAudit(Model $model, User $actor, AuditAction $action): Model
    {
        DB::transaction(function () use ($model, $actor, $action) {
            $model->save();
            $model->refresh();

            $this->record($actor, $action, $model, ['snapshot' => $this->snapshot($model)]);
        });

        return $model;
    }

    /** Enregistre les modifications d'un élément ; rien n'est tracé s'il n'y a aucun changement réel. */
    public function updateWithAudit(Model $model, User $actor, AuditAction $action): void
    {
        DB::transaction(function () use ($model, $actor, $action) {
            $changes = $this->changes($model);

            $model->save();

            if ($changes !== []) {
                $this->record($actor, $action, $model, ['changes' => $changes]);
            }
        });
    }

    public function toggleWithAudit(Model $model, User $actor, AuditAction $activated, AuditAction $deactivated): void
    {
        DB::transaction(function () use ($model, $actor, $activated, $deactivated) {
            $model->forceFill(['is_active' => ! $model->is_active])->save();

            $this->record($actor, $model->is_active ? $activated : $deactivated, $model);
        });
    }

    /** Les attributs masqués du modèle (mot de passe, jeton) n'apparaissent jamais. */
    private function snapshot(Model $model): array
    {
        return Arr::except($model->attributesToArray(), ['id', 'created_at', 'updated_at', 'deleted_at']);
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function changes(Model $model): array
    {
        $changes = [];

        foreach (array_keys($model->getDirty()) as $field) {
            // Jamais de secret dans le journal : mot de passe, jeton, etc.
            if ($field === 'updated_at' || in_array($field, $model->getHidden(), true)) {
                continue;
            }

            $changes[$field] = [
                'from' => $model->getOriginal($field),
                'to' => $model->getAttribute($field),
            ];
        }

        return $changes;
    }
}