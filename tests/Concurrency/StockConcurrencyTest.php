<?php

use App\Enums\MovementReason;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;
use App\Models\StockLot;
use App\Models\AuditLog;
use App\Services\IntegrityVerifier;
use App\Models\Device;
use App\Models\SyncOperation;
use Illuminate\Support\Str;
use App\Enums\MovementStatus;


// Ces tests écrivent réellement en base (pas de transaction) : ils vident la base de test.
uses(Tests\TestCase::class, DatabaseTruncation::class);

/**
 * Lance des processus PHP indépendants qui démarrent au même instant.
 *
 * @param  array<int, array{action: string, user: int, a: int, b?: int}>  $jobs
 * @return array<int, array{result: string, message?: string}>
 */
function runStockWorkers(array $jobs): array
{
    $startAt = microtime(true) + 4;
    $database = config('database.connections.'.config('database.default').'.database');

    $processes = array_map(function (array $job) use ($startAt, $database) {
        $process = new Process(
            [
                PHP_BINARY,
                __DIR__.'/worker.php',
                (string) $job['action'],
                (string) $job['user'],
                (string) $startAt,
                (string) $job['a'],
                (string) ($job['b'] ?? 0),
            ],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => $database],
        );
        $process->setTimeout(90);
        $process->start();

        return $process;
    }, $jobs);

    return array_map(function (Process $process) {
        $process->wait();

        // On lit la dernière ligne : d'éventuels avertissements PHP ne doivent pas casser l'analyse.
        $lines = array_values(array_filter(preg_split('/\R/', trim($process->getOutput()))));
        $decoded = json_decode(end($lines) ?: '', true);

        return $decoded ?? [
            'result' => 'error',
            'message' => $process->getOutput().$process->getErrorOutput(),
        ];
    }, $processes);
}

test('deux sorties simultanées ne peuvent pas consommer plus que le stock disponible', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);

    $results = runStockWorkers([
        ['action' => 'exit', 'user' => $user->id, 'a' => $product->id, 'b' => 7],
        ['action' => 'exit', 'user' => $user->id, 'a' => $product->id, 'b' => 7],
    ]);

    expect(collect($results)->pluck('result')->sort()->values()->all())
        ->toBe(['insufficient', 'ok']);

    expect($product->fresh()->quantity)->toBe(3);
    expect(StockMovement::where('product_id', $product->id)->count())->toBe(1);
});

test('cinq sorties simultanées de 3 sur un stock de 10 : exactement trois réussissent', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);

    $results = runStockWorkers(array_fill(0, 5, [
        'action' => 'exit', 'user' => $user->id, 'a' => $product->id, 'b' => 3,
    ]));

    $counts = collect($results)->pluck('result')->countBy();

    expect($counts->get('ok', 0))->toBe(3)
        ->and($counts->get('insufficient', 0))->toBe(2)
        ->and($counts->get('error', 0))->toBe(0);

    expect($product->fresh()->quantity)->toBe(1);

    $movements = StockMovement::where('product_id', $product->id)->orderBy('id')->get();
    expect($movements->pluck('quantity_before')->all())->toBe([10, 7, 4])
        ->and($movements->pluck('quantity_after')->all())->toBe([7, 4, 1]);
});

test('deux corrections simultanées du même mouvement : une seule réussit', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);
    $original = app(StockMovementService::class)->recordEntry($product, 5, $user);

    $results = runStockWorkers([
        ['action' => 'correct', 'user' => $user->id, 'a' => $original->id],
        ['action' => 'correct', 'user' => $user->id, 'a' => $original->id],
    ]);

    expect(collect($results)->pluck('result')->sort()->values()->all())
        ->toBe(['ok', 'refused']);

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::where('corrects_movement_id', $original->id)->count())->toBe(1);
});

test('cinq sorties simultanées sur un produit à lots : les lots et le stock restent cohérents', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();
    $service = app(StockMovementService::class);

    $service->recordEntry($product, 6, $user, lotNumber: 'PREMIER', expiresAt: now()->addDays(10));
    $service->recordEntry($product, 4, $user, lotNumber: 'SECOND', expiresAt: now()->addDays(20));

    $results = runStockWorkers(array_fill(0, 5, [
        'action' => 'exit', 'user' => $user->id, 'a' => $product->id, 'b' => 3,
    ]));

    $counts = collect($results)->pluck('result')->countBy();

    expect($counts->get('ok', 0))->toBe(3)
        ->and($counts->get('insufficient', 0))->toBe(2)
        ->and($counts->get('error', 0))->toBe(0);

    // 9 unités sorties en FEFO : le premier lot est vidé, il reste 1 unité dans le second.
    expect(StockLot::orderBy('id')->pluck('quantity_remaining')->all())->toBe([0, 1]);
    expect($product->fresh()->quantity)->toBe(1);
});

test("cinq écritures d'audit simultanées gardent la chaîne linéaire et vérifiable", function () {
    $user = User::factory()->admin()->create();

    $results = runStockWorkers(array_map(
        fn (int $i) => ['action' => 'audit', 'user' => $user->id, 'a' => $i],
        range(1, 5),
    ));

    expect(collect($results)->pluck('result')->unique()->all())->toBe(['ok']);
    expect(AuditLog::count())->toBe(5);

    $report = app(IntegrityVerifier::class)->verifyAuditChain();

    expect($report->issues)->toBe([])
        ->and($report->scanned['audit_rows'])->toBe(5);
});

test("le même identifiant envoyé deux fois simultanément n'est appliqué qu'une fois", function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $product = Product::factory()->create(['quantity' => 10]);
    $uuid = (string) Str::uuid();

    $results = runStockWorkers(array_fill(0, 2, [
        'action' => 'sync', 'user' => $user->id, 'a' => "{$device->id}|{$product->id}|{$uuid}", 'b' => 3,
    ]));

    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'duplicate']);

    expect($product->fresh()->quantity)->toBe(7)
        ->and(StockMovement::count())->toBe(1)
        ->and(SyncOperation::count())->toBe(1);
});

test('cinq appareils qui sortent 3 sur un stock de 10 : trois appliquées, deux en conflit', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $product = Product::factory()->create(['quantity' => 10]);

    $results = runStockWorkers(array_map(fn () => [
        'action' => 'sync', 'user' => $user->id, 'a' => "{$device->id}|{$product->id}|".Str::uuid(), 'b' => 3,
    ], range(1, 5)));

    $counts = collect($results)->pluck('result')->countBy();

    expect($counts->get('applied', 0))->toBe(3)
        ->and($counts->get('conflict', 0))->toBe(2)
        ->and($counts->get('error', 0))->toBe(0);

    expect($product->fresh()->quantity)->toBe(1)
        ->and(StockMovement::count())->toBe(3)
        ->and(SyncOperation::where('status', 'applied')->count())->toBe(3)
        ->and(SyncOperation::where('status', 'conflict')->count())->toBe(2);
});

test('deux résolutions simultanées du même conflit : une seule réussit', function () {
    $owner = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);
    $product = Product::factory()->create(['quantity' => 5]);
    $uuid = (string) Str::uuid();

    $operation = SyncOperation::create([
        'uuid' => $uuid,
        'device_id' => $device->id,
        'user_id' => $owner->id,
        'seq' => 1,
        'type' => 'exit',
        'product_id' => $product->id,
        'payload' => ['uuid' => $uuid, 'type' => 'exit', 'product_id' => $product->id, 'quantity' => 8, 'reason_code' => 'sale'],
        'occurred_at' => now()->subHour(),
        'status' => MovementStatus::Conflict,
        'reason' => 'Stock insuffisant : 5 disponible(s), 8 demandé(s).',
        'received_at' => now()->subMinutes(30),
    ]);

    $results = runStockWorkers(array_fill(0, 2, ['action' => 'resolve', 'user' => $owner->id, 'a' => $operation->id]));

    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['ok', 'refused']);

    expect($product->fresh()->quantity)->toBe(0)
        ->and(StockMovement::count())->toBe(1)
        ->and($operation->fresh()->status->value)->toBe('applied');
});