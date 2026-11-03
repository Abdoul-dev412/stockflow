<?php

namespace App\Policies;

use App\Models\Anomaly;
use App\Models\User;

class AnomalyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function update(User $user, Anomaly $anomaly): bool
    {
        return $this->viewAny($user);
    }
}