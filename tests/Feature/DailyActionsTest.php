<?php

use App\Enums\AnomalyRule;
use App\Enums\AnomalyStatus;
use App\Models\Anomaly;
use App\Models\IntegrityCheck;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Services\DailyActionsBuilder;
use App\Services\StockMovementService;
use Illuminate\Support\Str;

function actionsFor(User $user)
{
    return app(DailyActionsBuilder::class)->forUser($user);
}

function actionKeys(User $user): array
{
    return actionsFor($user)->pluck('key')->all();
}

/** Lot d'un produit sans seuil (donc jamais en alerte classique), expirant dans $days jours (négatif : expiré). */
function actionLot(string $number, int $days, int $quantity = 5, ?Product $product = null): StockLot
{
    $product ??= Product::factory()->tracked(false)->create(['minimum_stock' => 0, 'name' => "Produit {$number}"]);

    app(StockMovementService::class)->recordEntry(
        $product, $quantity, User::factory()->create(), lotNumber: $number, expiresAt: now()->addDays(max($days, 0)),
    );

    $lot = StockLot::where('product_id', $product->id)->where('lot_number', $number)->firstOrFail();
    $lot->forceFill(['expires_at' => today()->addDays($days)->toDateString()])->save();

    return $lot->fresh();
}

function actionAnomaly(string $message = 'Anomalie de test'): Anomaly
{
    return Anomaly::create([
        'rule_code' => AnomalyRule::Spike,
        'status' => AnomalyStatus::New,
        'message' => $message,
        'dedupe_key' => 'test:'.Str::uuid(),
        'occurred_at' => now(),
        'detected_at' => now(),
    ]);
}

function failedCheck(string $type = 'stock', int $issues = 2): IntegrityCheck
{
    return IntegrityCheck::create([
        'type' => $type,
        'status' => 'failed',
        'issues_count' => $issues,
        'signature' => hash('sha256', $type),
        'details' => ['examples' => ["Produit ALI-001 : incohérence de test ({$type})"]],
        'checked_at' => now(),
    ]);
}

test('un jour calme ne produit aucune action', function () {
    $user = User::factory()->admin()->create();

    expect(actionsFor($user))->toBeEmpty();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Actions du jour')
        ->assertSee('Rien à signaler');
});

test('les produits à commander forment une action avec les plus urgents en exemple', function () {
    $manager = User::factory()->create();

    foreach (range(1, 5) as $i) {
        Product::factory()->create(['name' => "Aliment {$i}", 'quantity' => 4, 'minimum_stock' => 10]);
    }

    $action = actionsFor($manager)->firstWhere('key', 'replenishment');

    expect($action->title)->toBe('Produits à commander')
        ->and($action->count)->toBe(5)
        ->and($action->items)->toHaveCount(3)
        ->and($action->items[0]['label'])->toContain('stock bas (4/10)')->toContain('16')
        ->and($action->url)->toBe(route('replenishment.index'))
        ->and($action->severity)->toBe('warning');
});

test('un produit en rupture rend l\'action critique', function () {
    Product::factory()->create(['name' => 'Aliment ponte', 'quantity' => 0, 'minimum_stock' => 10]);

    $action = actionsFor(User::factory()->create())->firstWhere('key', 'replenishment');

    expect($action->severity)->toBe('critical')
        ->and($action->items[0]['label'])->toContain('Aliment ponte')->toContain('en rupture');
});

test('les lots expirés forment une action critique, les lots proches une action à surveiller', function () {
    actionLot('LOT-EXPIRE', -3, 4);
    actionLot('LOT-BIENTOT', 5, 6);
    actionLot('LOT-LOIN', 60, 8);

    $actions = actionsFor(User::factory()->create());

    $expired = $actions->firstWhere('key', 'expired_lots');
    $soon = $actions->firstWhere('key', 'expiring_soon');

    expect($expired->severity)->toBe('critical')
        ->and($expired->count)->toBe(1)
        ->and($expired->detail)->toContain('4 unité(s)')
        ->and($expired->items[0]['label'])->toContain('LOT-EXPIRE')->toContain('Expiré depuis 3 j')
        ->and($expired->url)->toBe(route('expiries.index'));

    expect($soon->severity)->toBe('warning')
        ->and($soon->count)->toBe(1)
        ->and($soon->items[0]['label'])->toContain('LOT-BIENTOT')->toContain('Expire dans 5 j')
        ->and($soon->url)->toBe(route('expiries.index', ['days' => 7]));

    expect(collect($soon->items)->pluck('label')->implode(' '))->not->toContain('LOT-LOIN');
});

test("les actions d'administration ne sont montrées qu'à l'administrateur", function () {
    failedCheck();
    actionAnomaly();

    expect(actionKeys(User::factory()->create()))->toBe([])
        ->and(actionKeys(User::factory()->admin()->create()))->toBe(['integrity', 'anomalies']);
});

test("les écarts d'intégrité viennent de la dernière vérification de chaque type", function () {
    $admin = User::factory()->admin()->create();

    failedCheck('stock', 2);
    failedCheck('audit', 1);

    $action = actionsFor($admin)->firstWhere('key', 'integrity');

    expect($action->severity)->toBe('critical')
        ->and($action->count)->toBe(3)
        ->and($action->items)->toHaveCount(2)
        ->and($action->url)->toBe(route('audit.index'));

    // Une vérification ultérieure réussie efface l'écart correspondant.
    IntegrityCheck::create([
        'type' => 'stock', 'status' => 'ok', 'issues_count' => 0, 'details' => [], 'checked_at' => now()->addMinute(),
    ]);

    expect(actionsFor($admin)->firstWhere('key', 'integrity')->count)->toBe(1);

    IntegrityCheck::create([
        'type' => 'audit', 'status' => 'ok', 'issues_count' => 0, 'details' => [], 'checked_at' => now()->addMinute(),
    ]);

    expect(actionKeys($admin))->not->toContain('integrity');
});

test('seules les anomalies nouvelles comptent', function () {
    $admin = User::factory()->admin()->create();

    actionAnomaly('À examiner 1');
    actionAnomaly('À examiner 2');
    Anomaly::create([
        'rule_code' => AnomalyRule::Spike, 'status' => AnomalyStatus::Reviewed, 'message' => 'Déjà traitée',
        'dedupe_key' => 'test:'.Str::uuid(), 'occurred_at' => now(), 'detected_at' => now(),
    ]);

    $action = actionsFor($admin)->firstWhere('key', 'anomalies');

    expect($action->count)->toBe(2)->and($action->url)->toBe(route('anomalies.index'));
    expect(collect($action->items)->pluck('label')->implode(' '))->not->toContain('Déjà traitée');
});

test('les actions critiques passent avant les actions à surveiller', function () {
    $admin = User::factory()->admin()->create();

    actionLot('LOT-BIENTOT', 5);                 // à surveiller
    actionAnomaly();                              // à surveiller
    Product::factory()->create(['quantity' => 4, 'minimum_stock' => 10]);   // à commander (à surveiller)
    actionLot('LOT-EXPIRE', -2);                  // critique
    failedCheck();                                // critique

    $actions = actionsFor($admin);

    expect($actions->pluck('key')->all())->toBe(['integrity', 'expired_lots', 'replenishment', 'anomalies', 'expiring_soon'])
        ->and($actions->take(2)->pluck('severity')->unique()->all())->toBe(['critical'])
        ->and($actions->skip(2)->pluck('severity')->unique()->all())->toBe(['warning']);
});

test('chaque action mène à un écran utile', function () {
    $admin = User::factory()->admin()->create();

    failedCheck();
    actionAnomaly();
    actionLot('LOT-EXPIRE', -2);
    Product::factory()->create(['quantity' => 0, 'minimum_stock' => 10]);

    $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    foreach (['audit.index', 'anomalies.index', 'expiries.index', 'replenishment.index'] as $route) {
        $response->assertSee(route($route), false);
    }

    $response->assertSee("Écarts d'intégrité détectés")
        ->assertSee('Lots expirés à retirer du stock')
        ->assertSee('Produits à commander')
        ->assertSee('Anomalies à examiner');
});

test('le tableau de bord du gestionnaire ne montre pas les actions réservées', function () {
    failedCheck();
    actionAnomaly('Anomalie confidentielle');

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee("Écarts d'intégrité")
        ->assertDontSee('Anomalie confidentielle')
        ->assertSee('Rien à signaler');
});