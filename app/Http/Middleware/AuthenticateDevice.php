<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie un appareil par son jeton. Un appareil révoqué ou un compte désactivé passe ce filtre :
 * c'est le contrôleur qui rejette (et enregistre) ses opérations, pour que l'administrateur en soit informé.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $device = $token ? Device::findByToken($token) : null;

        if ($device === null) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Jeton absent ou invalide.',
            ], 401);
        }

        if ($device->tokenExpired()) {
            return response()->json([
                'error' => 'token_expired',
                'message' => 'Jeton expiré : reconnecte-toi et renouvelle le jeton de cet appareil. Les opérations en attente sont conservées.',
            ], 401);
        }

        $device->forceFill(['last_seen_at' => now()])->save();

        $request->attributes->set('device', $device);
        $request->setUserResolver(fn () => $device->user);

        return $next($request);
    }
}