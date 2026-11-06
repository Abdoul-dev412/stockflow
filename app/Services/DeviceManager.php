<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Exceptions\DeviceException;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceManager
{
    public function __construct(private AuditLogger $audit)
    {
    }

    /**
     * Enregistre un appareil et retourne le jeton EN CLAIR, qui ne sera plus jamais affichable.
     *
     * @return array{0: Device, 1: string}
     */
    public function register(User $user, string $name): array
    {
        return DB::transaction(function () use ($user, $name) {
            // Verrou sur l'utilisateur : deux enregistrements simultanés ne dépassent pas la limite.
            User::query()->lockForUpdate()->findOrFail($user->id);

            $max = (int) config('stockflow.sync.max_devices_per_user');
            $active = Device::query()->where('user_id', $user->id)->whereNull('revoked_at')->count();

            if ($active >= $max) {
                throw new DeviceException(
                    "Limite atteinte : {$max} appareil(s) actif(s) par compte. Révoque un appareil avant d'en enregistrer un autre."
                );
            }

            $token = $this->newToken();

            $device = Device::create([
                'user_id' => $user->id,
                'name' => $name,
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addDays((int) config('stockflow.sync.token_days')),
            ]);

            // Jamais le jeton, ni son empreinte, dans l'audit.
            $this->audit->record($user, AuditAction::DeviceRegistered, $device, ['name' => $device->name]);

            return [$device, $token];
        });
    }

    /** Remplace le jeton (l'ancien cesse immédiatement de fonctionner) et prolonge sa validité. */
    public function renewToken(Device $device, User $actor): string
    {
        return DB::transaction(function () use ($device, $actor) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);

            if ($locked->isRevoked()) {
                throw new DeviceException('Cet appareil est révoqué : enregistres-en un nouveau.');
            }

            $token = $this->newToken();

            $locked->forceFill([
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addDays((int) config('stockflow.sync.token_days')),
            ])->save();

            $this->audit->record($actor, AuditAction::DeviceTokenRenewed, $locked, ['name' => $locked->name]);

            $device->refresh();

            return $token;
        });
    }

    public function revoke(Device $device, User $actor): void
    {
        DB::transaction(function () use ($device, $actor) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);

            if ($locked->isRevoked()) {
                throw new DeviceException('Cet appareil est déjà révoqué.');
            }

            $locked->forceFill(['revoked_at' => now()])->save();

            $this->audit->record($actor, AuditAction::DeviceRevoked, $locked, ['name' => $locked->name]);
        });
    }

    private function newToken(): string
    {
        return 'sf_'.Str::random(40);
    }
}