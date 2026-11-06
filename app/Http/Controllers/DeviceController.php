<?php

namespace App\Http\Controllers;

use App\Exceptions\DeviceException;
use App\Models\Device;
use App\Services\DeviceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Device::class);

        $user = $request->user();

        $devices = Device::query()
            ->with('user:id,name')
            ->when(! $user->isAdmin(), fn ($query) => $query->where('user_id', $user->id))
            ->orderByDesc('id')
            ->get();

        return view('devices.index', ['devices' => $devices, 'isAdmin' => $user->isAdmin()]);
    }

    public function store(Request $request, DeviceManager $devices): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Device::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ], [
            'name.required' => "Donne un nom à l'appareil.",
            'name.max' => 'Le nom ne doit pas dépasser 100 caractères.',
        ]);

        try {
            [$device, $token] = $devices->register($request->user(), trim($data['name']));
        } catch (DeviceException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'device' => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'expires_at' => $device->token_expires_at->toIso8601String(),
                ],
                'token' => $token,
            ], 201);
        }

        return redirect()->route('devices.index')->with('device_token', $this->issued($device, $token));
    }

    public function renew(Request $request, Device $device, DeviceManager $devices): RedirectResponse
    {
        Gate::authorize('renew', $device);

        try {
            $token = $devices->renewToken($device, $request->user());
        } catch (DeviceException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('devices.index')->with('device_token', $this->issued($device, $token));
    }

    public function revoke(Request $request, Device $device, DeviceManager $devices): RedirectResponse
    {
        Gate::authorize('revoke', $device);

        try {
            $devices->revoke($device, $request->user());
        } catch (DeviceException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return back()->with('status', 'Appareil révoqué.');
    }

    /** @return array{name: string, token: string, expires_at: string} */
    private function issued(Device $device, string $token): array
    {
        return [
            'name' => $device->name,
            'token' => $token,
            'expires_at' => $device->token_expires_at->format('d/m/Y H:i'),
        ];
    }
}