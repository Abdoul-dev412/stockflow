<?php

use App\Enums\MovementReason;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $action, $userId, $startAt, $a, $b] = $argv;

// Élargit volontairement la fenêtre de course : une fois le verrou obtenu,
// ce processus patiente 300 ms avant de continuer. Sans verrou de ligne, un
// second processus lirait le même stock pendant ce temps.
DB::listen(function ($query) {
    if (str_contains(strtolower($query->sql), 'for update')) {
        usleep(300_000);
    }
});

$user = User::findOrFail((int) $userId);

// Attente de l'instant de départ commun à tous les processus.
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $service = app(StockMovementService::class);

    if ($action === 'exit') {
        $service->recordExit(Product::findOrFail((int) $a), (int) $b, $user, MovementReason::Sale);
    } else {
        $service->recordCorrection(StockMovement::findOrFail((int) $a), $user, 'Correction concurrente');
    }

    echo json_encode(['result' => 'ok']);
} catch (InsufficientStockException $e) {
    echo json_encode(['result' => 'insufficient']);
} catch (StockMovementException $e) {
    echo json_encode(['result' => 'refused', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'message' => $e->getMessage()]);
}