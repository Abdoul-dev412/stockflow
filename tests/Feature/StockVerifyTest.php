<?php

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\IntegrityCheck;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\IntegrityVerifier;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Un état complet et cohérent, construit uniquement par le service :
 * un produit simple (entrée, sortie, ajustement, correction) et un produit à lots (FEFO, ajustement de lot, correction).
 *
 * @return array{0: Product, 1: Product, 2: StockMovement, 3: StockMovement}
 */
function buildCoherentStock(): array
{
    $user = User::factory()->admin()->create();
    $service = app(StockMovementService::class);

    $plain = Product::factory()->create(['sku' => 'PLAIN-1', 'quantity' => 0]);
    $service->recordEntry($plain, 50, $user);
    $sale = $service->recordExit($plain, 20, $user, MovementReason::Sale);
    $service->recordAdjustment($plain, 25, $user, 'Inventaire');
    $service->recordCorrection($sale, $user, 'Vente annulée');                    // 25 + 20 = 45

    $tracked = Product::factory()->tracked(false)->create(['sku' => 'LOT-1']);
    $service->recordEntry($tracked, 30, $user, lotNumber: 'A', expiresAt: now()->addDays(10));
    $service->recordEntry($tracked, 20, $user, lotNumber: 'B', expiresAt: now()->addDays(20));
    $exit = $service->recordExit($tracked, 35, $user, MovementReason::Sale);      // A -30, B -5
    $service->recordAdjustment($tracked, 10, $user, 'Inventaire du lot', lotId: StockLot::where('lot_number', 'B')->value('id'));
    $service->recordCorrection($exit, $user, 'Vente annulée');                    // A +30, B +5 : total 45

    return [$plain->fresh(), $tracked->fresh(), $sale, $exit];
}

function stockIssues(): array
{
    return app(IntegrityVerifier::class)->verifyStock()->issues;
}

function stockChecks(): array
{
    return collect(stockIssues())->pluck('check')->unique()->sort()->values()->all();
}

// ------------------------------------------------------------------ État sain

test('une base vide est cohérente', function () {
    $report = app(IntegrityVerifier::class)->verifyStock();

    expect($report->ok())->toBeTrue()
        ->and($report->scanned)->toBe(['products' => 0, 'movements' => 0]);
});

test('un stock construit par le service, avec lots, ajustements et corrections, est cohérent', function () {
    [$plain, $tracked] = buildCoherentStock();

    $report = app(IntegrityVerifier::class)->verifyStock();

    expect($plain->quantity)->toBe(45)->and($tracked->quantity)->toBe(45);
    expect($report->issues)->toBe([])
        ->and($report->scanned['products'])->toBe(2)
        ->and($report->scanned['movements'])->toBe(9);
});

test('les mouvements qui ne sont pas appliqués sont ignorés', function () {
    [$plain] = buildCoherentStock();

    StockMovement::create([
        'uuid' => (string) Str::uuid(),
        'product_id' => $plain->id,
        'user_id' => User::first()->id,
        'type' => MovementType::Exit,
        'reason_code' => MovementReason::Sale,
        'quantity' => 4,
        'quantity_before' => 999,
        'quantity_after' => 1,
        'status' => MovementStatus::Conflict,
        'occurred_at' => now(),
        'recorded_at' => now(),
    ]);

    expect(stockIssues())->toBe([]);
});

// ------------------------------------------------------------------ Anomalies détectées

test('une quantité de produit modifiée à la main est détectée', function () {
    [$plain] = buildCoherentStock();

    DB::table('products')->where('id', $plain->id)->update(['quantity' => 99]);

    expect(stockChecks())->toBe(['quantity_mismatch'])
        ->and(stockIssues()[0]['message'])->toContain('PLAIN-1')->toContain('99')->toContain('45');
});

test('une quantité modifiée sur un produit à lots est détectée deux fois : dernier mouvement et somme des lots', function () {
    [, $tracked] = buildCoherentStock();

    DB::table('products')->where('id', $tracked->id)->update(['quantity' => 99]);

    expect(stockChecks())->toBe(['lot_sum_mismatch', 'quantity_mismatch']);
});

test('un produit avec du stock mais sans aucun mouvement est détecté', function () {
    Product::factory()->create(['sku' => 'ORPHELIN-1', 'quantity' => 5]);

    expect(stockChecks())->toBe(['quantity_mismatch'])
        ->and(stockIssues()[0]['message'])->toContain('ORPHELIN-1');
});

test('un produit supprimé logiquement reste contrôlé', function () {
    [$plain] = buildCoherentStock();
    $plain->delete();

    DB::table('products')->where('id', $plain->id)->update(['quantity' => 99]);

    expect(stockChecks())->toBe(['quantity_mismatch']);
});

test("une rupture de la chaîne avant/après est détectée", function () {
    [, , $sale] = buildCoherentStock();

    DB::table('stock_movements')->where('id', $sale->id)->update(['quantity_before' => 51]);

    expect(stockChecks())->toBe([
        'chain_break',
        'correction_mismatch',
        'delta_mismatch',
    ]);
});

test("un mouvement dont la quantité ne correspond pas à l'écart est détecté", function () {
    [, , $sale] = buildCoherentStock();

    DB::table('stock_movements')->where('id', $sale->id)->update(['quantity' => 19]);

    expect(stockChecks())->toBe(['delta_mismatch'])
        ->and(stockIssues()[0]['message'])->toContain("n° {$sale->id}");
});

test('un mouvement sans quantités avant/après est détecté', function () {
    [, , $sale] = buildCoherentStock();

    DB::table('stock_movements')->where('id', $sale->id)->update(['quantity_after' => null]);

    expect(stockChecks())->toContain('delta_mismatch');
});

test('un lot dont la quantité a été modifiée est détecté', function () {
    buildCoherentStock();

    DB::table('stock_lots')->where('lot_number', 'A')->update(['quantity_remaining' => 1]);

    expect(stockChecks())->toBe(['lot_allocation_mismatch', 'lot_sum_mismatch']);
});

test('une répartition supprimée est détectée au niveau du lot et du mouvement', function () {
    [, , , $exit] = buildCoherentStock();
    $lotA = StockLot::where('lot_number', 'A')->first();

    DB::table('movement_lot_allocations')
        ->where('movement_id', $exit->id)
        ->where('lot_id', $lotA->id)
        ->delete();

    expect(stockChecks())->toBe(['lot_allocation_mismatch', 'movement_allocation_mismatch']);
});

test('un lot rattaché à un produit non suivi par lots est détecté', function () {
    [$plain] = buildCoherentStock();

    StockLot::create([
        'product_id' => $plain->id, 'lot_number' => 'FANTOME', 'quantity_remaining' => 0, 'received_at' => now(),
    ]);

    expect(stockChecks())->toBe(['lots_on_untracked_product']);
});

test("une correction qui n'est plus l'inverse de son original est détectée", function () {
    [$plain, , $sale] = buildCoherentStock();
    $correction = StockMovement::where('corrects_movement_id', $sale->id)->firstOrFail();
    $entry = StockMovement::where('product_id', $plain->id)->where('type', MovementType::Entry)->firstOrFail();

    DB::table('stock_movements')->where('id', $correction->id)->update(['corrects_movement_id' => $entry->id]);

    expect(stockChecks())->toBe(['correction_mismatch']);
});

test('chaque anomalie nomme le produit ou le mouvement concerné, en français', function () {
    [$plain, , $sale] = buildCoherentStock();

    DB::table('products')->where('id', $plain->id)->update(['quantity' => 99]);
    DB::table('stock_movements')->where('id', $sale->id)->update(['quantity' => 19]);

    $messages = collect(stockIssues())->pluck('message')->implode(' ');

    expect($messages)->toContain('PLAIN-1')->toContain("Mouvement n° {$sale->id}");
});

test('la signature identifie un ensemble d\'anomalies', function () {
    [$plain, $tracked] = buildCoherentStock();
    $verifier = app(IntegrityVerifier::class);

    DB::table('products')->where('id', $plain->id)->update(['quantity' => 99]);
    $first = $verifier->verifyStock()->signature();

    expect($verifier->verifyStock()->signature())->toBe($first);

    DB::table('products')->where('id', $tracked->id)->update(['quantity' => 99]);

    expect($verifier->verifyStock()->signature())->not->toBe($first);
});

// ------------------------------------------------------------------ Commande stock:verify

test('stock:verify réussit sur un stock cohérent', function () {
    buildCoherentStock();

    $this->artisan('stock:verify')
        ->expectsOutputToContain('Stock cohérent : 2 produit(s) et 9 mouvement(s)')
        ->assertExitCode(0);

    expect(IntegrityCheck::count())->toBe(1)
        ->and(IntegrityCheck::first()->status)->toBe('ok')
        ->and(AuditLog::where('action', 'integrity.failed')->count())->toBe(0);
});

test('stock:verify échoue, décrit les anomalies et les trace', function () {
    [$plain] = buildCoherentStock();
    DB::table('products')->where('id', $plain->id)->update(['quantity' => 99]);

    $this->artisan('stock:verify')
        ->expectsOutputToContain('1 anomalie(s) détectée(s)')
        ->expectsOutputToContain('PLAIN-1')
        ->assertExitCode(1);

    $check = IntegrityCheck::lastOf('stock');
    $log = AuditLog::where('action', 'integrity.failed')->firstOrFail();

    expect($check->status)->toBe('failed')
        ->and($check->issues_count)->toBe(1)
        ->and($check->details['examples'][0])->toContain('PLAIN-1')
        ->and($log->user_id)->toBeNull()
        ->and($log->entity_type)->toBe('integrity')
        ->and($log->details['check'])->toBe('stock')
        ->and($log->details['issues'])->toBe(1);
});

test("une anomalie persistante n'est tracée qu'une fois, une nouvelle ou une réapparition l'est à nouveau", function () {
    [$plain, $tracked] = buildCoherentStock();

    DB::table('products')->where('id', $plain->id)->update(['quantity' => 99]);
    $this->artisan('stock:verify')->assertExitCode(1);
    $this->artisan('stock:verify')->assertExitCode(1);

    expect(AuditLog::where('action', 'integrity.failed')->count())->toBe(1);

    // Une anomalie supplémentaire change la signature : nouvelle trace.
    DB::table('products')->where('id', $tracked->id)->update(['quantity' => 99]);
    $this->artisan('stock:verify')->assertExitCode(1);

    expect(AuditLog::where('action', 'integrity.failed')->count())->toBe(2);

    // Retour à la normale, puis nouvelle panne : tracée à nouveau.
    DB::table('products')->where('id', $plain->id)->update(['quantity' => 45]);
    DB::table('products')->where('id', $tracked->id)->update(['quantity' => 45]);
    $this->artisan('stock:verify')->assertExitCode(0);

    DB::table('products')->where('id', $plain->id)->update(['quantity' => 99]);
    $this->artisan('stock:verify')->assertExitCode(1);

    expect(AuditLog::where('action', 'integrity.failed')->count())->toBe(3)
        ->and(IntegrityCheck::where('type', 'stock')->orderBy('id')->pluck('status')->all())
        ->toBe(['failed', 'failed', 'failed', 'ok', 'failed']);
});

test('les deux vérifications sont planifiées chaque jour', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('stock:verify')
        ->expectsOutputToContain('audit:verify')
        ->assertExitCode(0);
});

test('les données de démonstration passent la vérification', function () {
    // Garde-fou : un seeder qui échoue ou produit un stock incohérent est détecté ici.
    $this->seed();

    $this->artisan('stock:verify')->assertExitCode(0);
});

// ------------------------------------------------------------------ Écran d'audit

test("le journal d'audit affiche l'état des dernières vérifications", function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('audit.index'))
        ->assertOk()
        ->assertSee('Cohérence du stock')
        ->assertSee("Chaîne d'audit")
        ->assertSee('Jamais vérifié');

    [$plain] = buildCoherentStock();
    $this->artisan('stock:verify')->assertExitCode(0);

    $this->actingAs($admin)->get(route('audit.index'))->assertSee('Cohérent');

    DB::table('products')->where('id', $plain->id)->update(['quantity' => 99]);
    $this->artisan('stock:verify')->assertExitCode(1);

    $this->actingAs($admin)->get(route('audit.index'))
        ->assertSee('1 anomalie')
        ->assertSee('PLAIN-1')
        ->assertSee("Anomalie d'intégrité détectée");
});

test("l'écran d'audit reste réservé à l'administrateur", function () {
    $this->actingAs(User::factory()->create())->get(route('audit.index'))->assertForbidden();
});