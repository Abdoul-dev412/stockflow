<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function correct(User $user, StockMovement $movement): bool
    {
        return $user->isAdmin();
    }
}