<?php

use App\Enums\AnomalyRule;
use App\Enums\AnomalyStatus;
use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Anomaly;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AnomalyDetector;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

function anomalyDetector(): AnomalyDetector
{
    return app(AnomalyDetector::class);
}

/** Crée directement un mouvement appliqué (sans passer par le service, donc sans détection automatique). */
function anomalyMovement(
    Product $product,
    MovementType $type,
    int $quantity,
    MovementReason $reason,
    CarbonInterface $at,
    ?User $user = null,
    array $extra = [],
): StockMovement {
    $user ??= User::factory()->create();

    return StockMovement::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'product_id' => $product->id,
        'user_id' => $user->id,
        'type' => $type,
        'reason_code' => $reason,
        'quantity' => $quantity,
        'quantity_before' => 1000,
        'quantity_after' => $type === MovementType::Entry ? 1000 + $quantity : 1000 - $quantity,
        'status' => MovementStatus::Applied,
        'occurred_at' => $at,
        'recorded_at' => now(),
    ], $extra));
}

/** Produit avec une série de sorties passées (une par jour, la plus ancienne il y a 25 jours). */
function productWithExits(array $quantities, ?User $user = null, MovementReason $reason = MovementReason::Sale, string $sku = 'ALI-777'): array
{
    $user ??= User::factory()->create();
    $product = Product::factory()->create(['quantity' => 500, 'unit' => 'sac', 'sku' => $sku]);

    foreach ($quantities as $index => $quantity) {
        anomalyMovement($product, MovementType::Exit, $quantity, $reason, now()->subDays(25 - $index), $user);
    }

    return [$product, $user];
}

function anomaliesOf(AnomalyRule $rule)
{
    return Anomaly::where('rule_code', $rule->value)->get();
}

// ------------------------------------------------------------------ R1 : sortie exceptionnelle

test('une sortie supérieure à trois fois la médiane est signalée', function () {
    [$product, $user] = productWithExits([10, 10, 10, 10, 10]);
    $spike = anomalyMovement($product, MovementType::Exit, 31, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($spike))->toBe(1);

    $anomaly = Anomaly::firstOrFail();

    expect($anomaly->rule_code)->toBe(AnomalyRule::Spike)
        ->and($anomaly->status)->toBe(AnomalyStatus::New)
        ->and($anomaly->product_id)->toBe($product->id)
        ->and($anomaly->user_id)->toBe($user->id)
        ->and($anomaly->movement_id)->toBe($spike->id)
        ->and($anomaly->message)->toContain('ALI-777')->toContain('31 sac')->toContain('médiane (10)')
        ->and($anomaly->details['median'])->toEqual(10)
        ->and($anomaly->details['sample'])->toBe(5);
});

test('une sortie égale à trois fois la médiane est normale', function () {
    [$product, $user] = productWithExits([10, 10, 10, 10, 10]);

    $movement = anomalyMovement($product, MovementType::Exit, 30, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($movement))->toBe(0)->and(Anomaly::count())->toBe(0);
});

test('avec un nombre pair de sorties, la médiane est la moyenne des deux valeurs centrales', function () {
    [$product, $user] = productWithExits([5, 5, 10, 20, 20, 20]);   // médiane (10 + 20) / 2 = 15

    $normal = anomalyMovement($product, MovementType::Exit, 45, MovementReason::Sale, now()->subDay(), $user);
    expect(anomalyDetector()->evaluate($normal))->toBe(0);

    [$other, $user2] = productWithExits([5, 5, 10, 20, 20, 20], sku: 'ALI-778');
    $spike = anomalyMovement($other, MovementType::Exit, 46, MovementReason::Sale, now(), $user2);
    expect(anomalyDetector()->evaluate($spike))->toBe(1);
});

test('sans assez de sorties de référence, on ne juge pas', function () {
    [$product, $user] = productWithExits([10, 10, 10, 10]);

    $movement = anomalyMovement($product, MovementType::Exit, 500, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($movement))->toBe(0);
});

test('les sorties de plus de trente jours ne comptent pas dans la référence', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 500]);

    foreach ([40, 38, 36] as $days) {
        anomalyMovement($product, MovementType::Exit, 10, MovementReason::Sale, now()->subDays($days), $user);
    }
    foreach ([5, 4] as $days) {
        anomalyMovement($product, MovementType::Exit, 10, MovementReason::Sale, now()->subDays($days), $user);
    }

    $movement = anomalyMovement($product, MovementType::Exit, 500, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($movement))->toBe(0);   // seulement 2 sorties dans la fenêtre
});

test("les retraits pour péremption ne sont ni jugés ni utilisés comme référence", function () {
    [$product, $user] = productWithExits([10, 10, 10, 10]);
    anomalyMovement($product, MovementType::Exit, 10, MovementReason::Expiry, now()->subDays(2), $user);

    $spike = anomalyMovement($product, MovementType::Exit, 500, MovementReason::Sale, now(), $user);
    expect(anomalyDetector()->evaluate($spike))->toBe(0);   // 4 sorties de référence seulement

    $expiry = anomalyMovement($product, MovementType::Exit, 500, MovementReason::Expiry, now(), $user);
    anomalyDetector()->evaluate($expiry);

    expect(anomaliesOf(AnomalyRule::Spike))->toHaveCount(0);
});

test('une sortie annulée par une correction ne compte pas dans la référence', function () {
    [$product, $user] = productWithExits([10, 10, 10, 10, 10]);
    $first = StockMovement::where('product_id', $product->id)->orderBy('id')->first();

    anomalyMovement($product, MovementType::Correction, 10, MovementReason::Correction, now()->subDay(), $user, [
        'corrects_movement_id' => $first->id,
        'quantity_after' => 1010,
    ]);

    $spike = anomalyMovement($product, MovementType::Exit, 500, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($spike))->toBe(0);   // 4 sorties valides seulement
});

test('rejouer la détection ne crée jamais deux fois la même anomalie', function () {
    [$product, $user] = productWithExits([10, 10, 10, 10, 10]);
    $spike = anomalyMovement($product, MovementType::Exit, 100, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($spike))->toBe(1)
        ->and(anomalyDetector()->evaluate($spike))->toBe(0);

    expect(Anomaly::count())->toBe(1);
});

test('un mouvement qui n\'est pas appliqué n\'est jamais jugé', function () {
    [$product, $user] = productWithExits([10, 10, 10, 10, 10]);
    $pending = anomalyMovement($product, MovementType::Exit, 100, MovementReason::Sale, now(), $user, [
        'status' => MovementStatus::Conflict,
    ]);

    expect(anomalyDetector()->evaluate($pending))->toBe(0);
});

// ------------------------------------------------------------------ R2 : ajustements négatifs répétés

function negativeAdjustment(Product $product, User $user, int $daysAgo): StockMovement
{
    return anomalyMovement($product, MovementType::Adjustment, 5, MovementReason::Inventory, now()->subDays($daysAgo), $user);
}

test('trois ajustements négatifs en sept jours par le même utilisateur sont signalés', function () {
    $user = User::factory()->create(['name' => 'Bruno Gestionnaire']);
    $product = Product::factory()->create(['sku' => 'VAC-001']);

    $first = negativeAdjustment($product, $user, 3);
    $second = negativeAdjustment($product, $user, 2);
    $third = negativeAdjustment($product, $user, 1);

    expect(anomalyDetector()->evaluate($first))->toBe(0)
        ->and(anomalyDetector()->evaluate($second))->toBe(0)
        ->and(anomalyDetector()->evaluate($third))->toBe(1);

    $anomaly = anomaliesOf(AnomalyRule::RepeatedNegativeAdjustments)->first();

    expect($anomaly->user_id)->toBe($user->id)
        ->and($anomaly->movement_id)->toBe($third->id)
        ->and($anomaly->message)->toContain('Bruno Gestionnaire')->toContain('3 ajustements négatifs')->toContain('VAC-001');
});

test('les ajustements à la hausse et ceux des autres utilisateurs ne comptent pas', function () {
    $product = Product::factory()->create();
    $user = User::factory()->create();
    $other = User::factory()->create();

    negativeAdjustment($product, $user, 3);
    negativeAdjustment($product, $other, 2);
    anomalyMovement($product, MovementType::Adjustment, 5, MovementReason::Inventory, now()->subDay(), $user, [
        'quantity_after' => 1005,   // ajustement à la hausse
    ]);

    $last = negativeAdjustment($product, $user, 0);

    expect(anomalyDetector()->evaluate($last))->toBe(0);   // 2 négatifs seulement pour cet utilisateur
});

test('des ajustements espacés de plus de sept jours ne sont pas signalés', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    negativeAdjustment($product, $user, 20);
    negativeAdjustment($product, $user, 10);
    $last = negativeAdjustment($product, $user, 1);

    expect(anomalyDetector()->evaluate($last))->toBe(0);
});

test("une série ne produit qu'une anomalie, une nouvelle série plus tard en produit une autre", function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $movements = collect([20, 19, 18, 17, 5, 4, 3])
        ->map(fn (int $days) => negativeAdjustment($product, $user, $days));

    foreach ($movements as $movement) {
        anomalyDetector()->evaluate($movement);
    }

    // Série 1 : jours -20 à -17 (une seule anomalie, malgré le quatrième ajustement).
    // Série 2 : jours -5 à -3, plus de sept jours après la première.
    expect(anomaliesOf(AnomalyRule::RepeatedNegativeAdjustments))->toHaveCount(2);
});

test('une ajustement annulé par une correction ne compte pas', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $first = negativeAdjustment($product, $user, 3);
    negativeAdjustment($product, $user, 2);
    $third = negativeAdjustment($product, $user, 1);

    anomalyMovement($product, MovementType::Correction, 5, MovementReason::Correction, now(), $user, [
        'corrects_movement_id' => $first->id,
        'quantity_after' => 1005,
    ]);

    expect(anomalyDetector()->evaluate($third))->toBe(0);
});

// ------------------------------------------------------------------ R3 : pertes anormales

/** Quatre ventes de $sale unités, puis trois pertes de 10 unités. Retourne le dernier mouvement de perte. */
function productWithLosses(int $sale, string $sku = 'VAC-777', array $lossReasons = [MovementReason::Loss, MovementReason::Breakage, MovementReason::Theft]): array
{
    $user = User::factory()->create();
    $product = Product::factory()->create(['sku' => $sku, 'unit' => 'boîte', 'quantity' => 500]);

    foreach (range(10, 7) as $days) {
        anomalyMovement($product, MovementType::Exit, $sale, MovementReason::Sale, now()->subDays($days), $user);
    }

    $last = null;
    foreach ($lossReasons as $index => $reason) {
        $last = anomalyMovement($product, MovementType::Exit, 10, $reason, now()->subDays(6 - $index), $user);
    }

    return [$product, $last];
}

test('des pertes supérieures à vingt pour cent des sorties sont signalées', function () {
    [$product, $last] = productWithLosses(10);   // 30 perdus sur 70 sortis : 42,9 %

    expect(anomalyDetector()->evaluate($last))->toBe(1);

    $anomaly = anomaliesOf(AnomalyRule::LossShare)->first();

    expect($anomaly->product_id)->toBe($product->id)
        ->and($anomaly->user_id)->toBeNull()
        ->and($anomaly->movement_id)->toBe($last->id)
        ->and($anomaly->message)->toContain('VAC-777')->toContain('42,9')
        ->and($anomaly->details['percent'])->toEqualWithDelta(42.9, 0.05)
        ->and($anomaly->details['lost'])->toBe(30)
        ->and($anomaly->details['total'])->toBe(70);
});

test('exactement vingt pour cent n\'est pas une anomalie', function () {
    [, $last] = productWithLosses(20, 'VAC-778', [MovementReason::Loss]);   // 10 perdus sur 90... puis ajusté ci-dessous

    // 4 ventes de 20 = 80 ; une perte de 20 = 20 % pile.
    $product = Product::factory()->create(['quantity' => 500]);
    $user = User::factory()->create();

    foreach (range(10, 7) as $days) {
        anomalyMovement($product, MovementType::Exit, 20, MovementReason::Sale, now()->subDays($days), $user);
    }
    $loss = anomalyMovement($product, MovementType::Exit, 20, MovementReason::Loss, now()->subDays(5), $user);

    expect(anomalyDetector()->evaluate($loss))->toBe(0);
    expect(anomaliesOf(AnomalyRule::LossShare)->where('product_id', $product->id))->toHaveCount(0);
});

test('avec moins de trois sorties, on ne juge pas la part des pertes', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 500]);

    anomalyMovement($product, MovementType::Exit, 10, MovementReason::Sale, now()->subDays(3), $user);
    $loss = anomalyMovement($product, MovementType::Exit, 10, MovementReason::Loss, now()->subDay(), $user);

    expect(anomalyDetector()->evaluate($loss))->toBe(0);
});

test('une péremption compte comme une perte', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 500]);

    foreach ([10, 9, 8] as $days) {
        anomalyMovement($product, MovementType::Exit, 10, MovementReason::Sale, now()->subDays($days), $user);
    }
    $expiry = anomalyMovement($product, MovementType::Exit, 20, MovementReason::Expiry, now()->subDay(), $user);

    expect(anomalyDetector()->evaluate($expiry))->toBe(1);   // 20 sur 50 : 40 %
    expect(anomaliesOf(AnomalyRule::Spike))->toHaveCount(0);
});

test('une perte annulée par une correction ne compte pas', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 500]);

    foreach ([10, 9, 8] as $days) {
        anomalyMovement($product, MovementType::Exit, 10, MovementReason::Sale, now()->subDays($days), $user);
    }
    $loss = anomalyMovement($product, MovementType::Exit, 10, MovementReason::Loss, now()->subDays(2), $user);
    anomalyMovement($product, MovementType::Correction, 10, MovementReason::Correction, now()->subDay(), $user, [
        'corrects_movement_id' => $loss->id,
        'quantity_after' => 1010,
    ]);

    expect(anomalyDetector()->evaluate($loss))->toBe(0);
});

test('une seule anomalie de pertes par produit et par fenêtre', function () {
    [$product, $last] = productWithLosses(10);
    anomalyDetector()->evaluate($last);

    $more = anomalyMovement($product, MovementType::Exit, 10, MovementReason::Loss, now(), User::first());

    expect(anomalyDetector()->evaluate($more))->toBe(0)
        ->and(anomaliesOf(AnomalyRule::LossShare))->toHaveCount(1);
});

// ------------------------------------------------------------------ R4 : produit dormant

test('une sortie sur un produit sans mouvement depuis plus de 90 jours est signalée', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['sku' => 'MAT-777', 'unit' => 'unité', 'quantity' => 100]);

    anomalyMovement($product, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(100), $user);
    $sale = anomalyMovement($product, MovementType::Exit, 5, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($sale))->toBe(1);

    $anomaly = anomaliesOf(AnomalyRule::DormantProduct)->first();

    expect($anomaly->movement_id)->toBe($sale->id)
        ->and($anomaly->user_id)->toBe($user->id)
        ->and($anomaly->message)->toContain('MAT-777')->toContain('100 jours')
        ->and($anomaly->details['idle_days'])->toBe(100);
});

test("un produit récent n'est pas dormant", function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 100]);

    anomalyMovement($product, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(10), $user);
    $sale = anomalyMovement($product, MovementType::Exit, 5, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($sale))->toBe(0);
});

test("la dernière vente, et non la dernière entrée, mesure l'inactivité", function () {
    $user = User::factory()->create();
    $dormant = Product::factory()->create(['quantity' => 100]);
    $active = Product::factory()->create(['quantity' => 100]);

    // Vente il y a 100 jours, réapprovisionnement récent : le produit est dormant.
    anomalyMovement($dormant, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(150), $user);
    anomalyMovement($dormant, MovementType::Exit, 5, MovementReason::Sale, now()->subDays(100), $user);
    anomalyMovement($dormant, MovementType::Entry, 50, MovementReason::Reception, now()->subDays(5), $user);
    $dormantSale = anomalyMovement($dormant, MovementType::Exit, 5, MovementReason::Sale, now(), $user);

    // Vente il y a 30 jours : pas dormant.
    anomalyMovement($active, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(150), $user);
    anomalyMovement($active, MovementType::Exit, 5, MovementReason::Sale, now()->subDays(30), $user);
    $activeSale = anomalyMovement($active, MovementType::Exit, 5, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($dormantSale))->toBe(1)
        ->and(anomalyDetector()->evaluate($activeSale))->toBe(0);
});

test("une perte ne remet pas le compteur d'inactivité à zéro", function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 100]);

    anomalyMovement($product, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(120), $user);
    anomalyMovement($product, MovementType::Exit, 5, MovementReason::Loss, now()->subDays(10), $user);
    $sale = anomalyMovement($product, MovementType::Exit, 5, MovementReason::Sale, now(), $user);

    expect(anomalyDetector()->evaluate($sale))->toBe(1);
});

test('seules les sorties de marchandises sont jugées sur un produit dormant', function () {
    $user = User::factory()->create();

    $judged = [
        MovementReason::Sale->value => 1,
        MovementReason::InternalUse->value => 1,
        MovementReason::Theft->value => 1,
        MovementReason::Other->value => 1,
        MovementReason::Loss->value => 0,
        MovementReason::Breakage->value => 0,
        MovementReason::Expiry->value => 0,
    ];

    foreach ($judged as $reason => $expected) {
        $product = Product::factory()->create(['quantity' => 100]);
        anomalyMovement($product, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(120), $user);
        $exit = anomalyMovement($product, MovementType::Exit, 5, MovementReason::from($reason), now(), $user);

        expect(anomalyDetector()->evaluate($exit))->toBe($expected, "motif {$reason}");
    }
});

test("un tout premier mouvement n'est jamais jugé dormant", function () {
    $product = Product::factory()->create(['quantity' => 100]);
    $exit = anomalyMovement($product, MovementType::Exit, 5, MovementReason::Sale, now());

    expect(anomalyDetector()->evaluate($exit))->toBe(0);
});

// ------------------------------------------------------------------ Branchement et rattrapage

test('la détection se déclenche automatiquement après un mouvement enregistré par le service', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 100]);
    anomalyMovement($product, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(120), $user);

    app(StockMovementService::class)->recordExit($product, 5, $user, MovementReason::Sale);

    expect(Anomaly::count())->toBe(1)
        ->and(Anomaly::first()->rule_code)->toBe(AnomalyRule::DormantProduct);
});

test("une panne de la détection ne fait jamais échouer l'opération de stock", function () {
    $this->mock(AnomalyDetector::class, fn ($mock) => $mock
        ->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('détection indisponible')));

    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 100]);

    $movement = app(StockMovementService::class)->recordExit($product, 5, $user, MovementReason::Sale);

    expect($product->fresh()->quantity)->toBe(95)
        ->and($movement->quantity_after)->toBe(95)
        ->and(StockMovement::count())->toBe(1);
});

test('les opérations refusées ne déclenchent aucune détection', function () {
    $product = Product::factory()->create(['quantity' => 5]);

    expect(fn () => app(StockMovementService::class)
        ->recordExit($product, 50, User::factory()->create(), MovementReason::Sale))
        ->toThrow(App\Exceptions\InsufficientStockException::class);

    expect(Anomaly::count())->toBe(0);
});

test('anomalies:detect rattrape les mouvements récents, de façon idempotente', function () {
    [$product, $user] = productWithExits([10, 10, 10, 10, 10]);
    anomalyMovement($product, MovementType::Exit, 100, MovementReason::Sale, now(), $user);

    $this->artisan('anomalies:detect')
        ->expectsOutputToContain('1 nouvelle(s) anomalie(s)')
        ->assertExitCode(0);

    $this->artisan('anomalies:detect')
        ->expectsOutputToContain('0 nouvelle(s) anomalie(s)')
        ->assertExitCode(0);

    expect(Anomaly::count())->toBe(1);
});

test("l'option --days élargit la fenêtre de rattrapage", function () {
    [$product, $user] = productWithExits([10, 10, 10, 10, 10]);
    $spike = anomalyMovement($product, MovementType::Exit, 100, MovementReason::Sale, now()->subDays(5), $user);
    DB::table('stock_movements')
    ->where('id', $spike->id)
    ->update(['recorded_at' => now()->subDays(5)]);

    // Les sorties de référence ont aussi été enregistrées « maintenant » : on les date de la même façon.
    DB::table('stock_movements')
    ->where('id', '<>', $spike->id)
    ->update(['recorded_at' => now()->subDays(20)]);

    $this->artisan('anomalies:detect')->expectsOutputToContain('0 nouvelle(s)')->assertExitCode(0);
    $this->artisan('anomalies:detect', ['--days' => 10])->expectsOutputToContain('1 nouvelle(s)')->assertExitCode(0);
});

test('le rattrapage réévalue aussi la part des pertes de chaque produit concerné', function () {
    [$product] = productWithLosses(10);
    StockMovement::query()->update(['recorded_at' => now()->subDays(20)]);   // hors de la fenêtre de rattrapage

    $this->artisan('anomalies:detect')->assertExitCode(0);

    expect(anomaliesOf(AnomalyRule::LossShare))->toHaveCount(1)
        ->and(anomaliesOf(AnomalyRule::LossShare)->first()->product_id)->toBe($product->id);
});

test('la détection est planifiée chaque nuit', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('anomalies:detect')->assertExitCode(0);
});