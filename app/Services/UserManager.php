<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Exceptions\UserManagementException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserManager
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function create(User $actor, string $name, string $email, Role $role, string $password): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);

        $user->forceFill([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => true,
            // L'administrateur se porte garant de l'adresse : pas de vérification par email.
            'email_verified_at' => now(),
        ]);

        /** @var User */
        return $this->audit->createWithAudit($user, $actor, AuditAction::UserCreated);
    }

    public function update(User $actor, User $target, string $name, string $email, Role $role): User
    {
        return DB::transaction(function () use ($actor, $target, $name, $email, $role) {
            [$fresh, $activeAdmins] = $this->lockRelevantUsers($target);

            if ($role !== $fresh->role) {
                if ($actor->is($fresh)) {
                    throw new UserManagementException('Tu ne peux pas modifier ton propre rôle.');
                }

                if ($fresh->role === Role::Admin && $fresh->is_active && $activeAdmins <= 1) {
                    throw new UserManagementException('Il doit toujours rester au moins un administrateur actif.');
                }
            }

            $fresh->fill(['name' => $name, 'email' => $email]);
            $fresh->forceFill(['role' => $role]);

            $action = $fresh->isDirty('role') ? AuditAction::UserRoleChanged : AuditAction::UserUpdated;

            $this->audit->updateWithAudit($fresh, $actor, $action);

            return $fresh;
        }, 3);
    }

    public function toggle(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target) {
            [$fresh, $activeAdmins] = $this->lockRelevantUsers($target);

            if ($fresh->is_active) {
                if ($actor->is($fresh)) {
                    throw new UserManagementException('Tu ne peux pas désactiver ton propre compte.');
                }

                if ($fresh->role === Role::Admin && $activeAdmins <= 1) {
                    throw new UserManagementException('Il doit toujours rester au moins un administrateur actif.');
                }
            }

            $this->audit->toggleWithAudit($fresh, $actor, AuditAction::UserActivated, AuditAction::UserDeactivated);

            if (! $fresh->is_active) {
                $this->closeSessionsOf($fresh);
            }

            return $fresh;
        }, 3);
    }

    public function resetPassword(User $actor, User $target, string $password): User
    {
        if ($actor->is($target)) {
            throw new UserManagementException('Pour changer ton propre mot de passe, utilise ta page de profil.');
        }

        return DB::transaction(function () use ($actor, $target, $password) {
            $fresh = User::query()->lockForUpdate()->findOrFail($target->getKey());

            // Le mot de passe est haché par le cast du modèle.
            $fresh->forceFill([
                'password' => $password,
                'must_change_password' => true,
                'remember_token' => null,
            ])->save();

            $this->audit->record($actor, AuditAction::UserPasswordReset, $fresh);
            $this->closeSessionsOf($fresh);

            return $fresh;
        }, 3);
    }

    /**
     * Verrouille l'utilisateur visé et tous les administrateurs, dans un ordre fixe,
     * pour que la règle « au moins un administrateur actif » résiste à la concurrence.
     *
     * @return array{0: User, 1: int} l'utilisateur relu sous verrou, le nombre d'administrateurs actifs
     */
    private function lockRelevantUsers(User $target): array
    {
        $users = User::query()
            ->where(fn ($query) => $query
                ->where('role', Role::Admin->value)
                ->orWhere('id', $target->getKey()))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $fresh = $users->firstWhere('id', $target->getKey())
            ?? throw new UserManagementException('Utilisateur introuvable.');

        $activeAdmins = $users
            ->filter(fn (User $user) => $user->role === Role::Admin && $user->is_active)
            ->count();

        return [$fresh, $activeAdmins];
    }

    private function closeSessionsOf(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
    }
}