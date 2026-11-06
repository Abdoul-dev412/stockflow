<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    /** Seul le propriétaire obtient un jeton : un administrateur ne peut pas se faire passer pour un autre. */
    public function renew(User $user, Device $device): bool
    {
        return $device->user_id === $user->id && ! $device->isRevoked();
    }

    /** Le propriétaire ou un administrateur peut révoquer. */
    public function revoke(User $user, Device $device): bool
    {
        return ($device->user_id === $user->id || $user->isAdmin()) && ! $device->isRevoked();
    }
}