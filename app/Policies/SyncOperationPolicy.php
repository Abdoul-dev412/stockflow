<?php

namespace App\Policies;

use App\Models\SyncOperation;
use App\Models\User;

class SyncOperationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    /** Résoudre un conflit : l'administrateur, ou le gestionnaire pour ses propres opérations. */
    public function resolve(User $user, SyncOperation $operation): bool
    {
        return $user->is_active && ($user->isAdmin() || $operation->user_id === $user->id);
    }

    /** Valider ou refuser un inventaire saisi hors ligne : administrateur seulement. */
    public function validateAdjustment(User $user, SyncOperation $operation): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}