<?php

use App\Enums\MovementReason;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;
use App\Models\StockLot;


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