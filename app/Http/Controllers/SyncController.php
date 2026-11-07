<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\SyncCatalog;
use App\Services\SyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function operations(Request $request, SyncService $sync): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:'.SyncService::MAX_BATCH],
        ]);

        $outcome = $sync->processBatch($device, $request->input('operations'));

        return response()->json([
            'results' => $outcome['results'],
            'blocked' => $outcome['blocked'],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function changes(Request $request, SyncCatalog $catalog, SyncService $sync): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        if ($blocked = $sync->blocked($device)) {
            return response()->json(['error' => $blocked['code'], 'message' => $blocked['message']], 403);
        }

        $data = $request->validate(['since' => ['nullable', 'date']]);

        return response()->json($catalog->changes(isset($data['since']) ? Carbon::parse($data['since']) : null, $device));
    }
}