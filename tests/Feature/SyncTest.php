<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Device;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SyncOperation;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ------------------------------------------------------------------ Outils

/** @return array{0: Device, 1: string, 2: User} */
function syncDevice(?User $user = null, array $attributes = []): array
{
    $user ??= User::factory()->create();
    $token = 'sf_'.Str::random(40);
    $device = Device::factory()->withToken($token)->create(['user_id' => $user->id] + $attributes);

    return [$device, $token, $user];
}

function syncOp(array $overrides = []): array
{
    static $seq = 0;

    return array_merge([
        'uuid' => (string) Str::uuid(),
        'seq' => ++$seq,
        'type' => 'entry',
        'product_id' => null,
        'quantity' => 5,
        'reason_code' => 'reception',
        'occurred_at' => now()->subMinutes(10)->toIso8601String(),
    ], $overrides);
}

function syncExit(Product $product, int $quantity, array $overrides = []): array
{
    return syncOp(array_merge([
        'type' => 'exit',
        'product_id' => $product->id,
        'quantity' => $quantity,
        'reason_code' => 'sale',
    ], $overrides));
}

function syncAdjustment(Product $product, int $counted, array $overrides = []): array
{
    return syncOp(array_merge([
        'type' => 'adjustment',
        'product_id' => $product->id,
        'counted_quantity' => $counted,
        'quantity' => null,
        'reason_code' => null,
        'comment' => 'Comptage hors ligne',
    ], $overrides));
}

function syncPost(string $token, array $operations)
{
    return test()->withToken($token)->postJson('/sync/operations', ['operations' => $operations]);
}

// ------------------------------------------------------------------ Authentification de l'appareil

test('sans jeton valide, la synchronisation est refusée et rien n\'est enregistré', function () {
    $this->postJson('/sync/operations', ['operations' => [syncOp()]])
        ->assertUnauthorized()->assertJsonPath('error', 'invalid_token');

    $this->withToken('sf_inconnu')->postJson('/sync/operations', ['operations' => [syncOp()]])->assertUnauthorized();
    $this->withToken('sf_inconnu')->getJson('/sync/changes')->assertUnauthorized();

    expect(SyncOperation::count())->toBe(0);
});

test('un jeton expiré est refusé avec un message de reconnexion, sans rien enregistrer', function () {
    [, $token] = syncDevice(null, ['token_expires_at' => now()->subMinute()]);
    $product = Product::factory()->create(['quantity' => 10]);

    syncPost($token, [syncExit($product, 3)])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'token_expired');

    $this->withToken($token)->getJson('/sync/changes')->assertUnauthorized()->assertJsonPath('error', 'token_expired');

    expect(SyncOperation::count())->toBe(0)->and($product->fresh()->quantity)->toBe(10);
});

test("un appareil révoqué voit ses opérations rejetées, enregistrées et signalées à l'administrateur", function () {
    [$device, $token] = syncDevice(null, ['revoked_at' => now()]);
    $product = Product::factory()->create(['quantity' => 10]);
    $op = syncExit($product, 3);

    syncPost($token, [$op])
        ->assertOk()
        ->assertJsonPath('blocked', 'device_revoked')
        ->assertJsonPath('results.0.status', 'rejected');

    expect(SyncOperation::firstOrFail()->status->value)->toBe('rejected')
        ->and(SyncOperation::first()->reason)->toContain('révoqué')
        ->and($product->fresh()->quantity)->toBe(10)
        ->and(StockMovement::count())->toBe(0);

    $log = AuditLog::where('action', 'sync.rejected')->firstOrFail();

    expect($log->entity_type)->toBe('device')
        ->and($log->entity_id)->toBe($device->id)
        ->and($log->details['reason'])->toBe('device_revoked')
        ->and($log->details['operations'])->toBe(1);

    // Un renvoi donne le résultat initial et ne crée pas une seconde alerte.
    syncPost($token, [$op])->assertJsonPath('results.0.status', 'duplicate')->assertJsonPath('results.0.initial_status', 'rejected');
    expect(AuditLog::where('action', 'sync.rejected')->count())->toBe(1);
});

test('un compte désactivé voit toutes ses opérations rejetées', function () {
    [, $token] = syncDevice(User::factory()->inactive()->create());
    $product = Product::factory()->create(['quantity' => 10]);

    syncPost($token, [syncExit($product, 3)])
        ->assertOk()
        ->assertJsonPath('blocked', 'user_inactive')
        ->assertJsonPath('results.0.status', 'rejected');

    expect(SyncOperation::first()->reason)->toContain('désactivé')->and($product->fresh()->quantity)->toBe(10);

    $this->withToken($token)->getJson('/sync/changes')->assertForbidden()->assertJsonPath('error', 'user_inactive');
});

test('chaque requête authentifiée met à jour la dernière activité de l\'appareil', function () {
    [$device, $token] = syncDevice();

    expect($device->last_seen_at)->toBeNull();

    $this->withToken($token)->getJson('/sync/changes')->assertOk();

    expect($device->fresh()->last_seen_at)->not->toBeNull();
});

test('la limitation de débit protège les points d\'accès', function () {
    config(['stockflow.sync.rate_limit_per_minute' => 2]);
    [, $token] = syncDevice();

    $this->withToken($token)->getJson('/sync/changes')->assertOk();
    $this->withToken($token)->getJson('/sync/changes')->assertOk();
    $this->withToken($token)->getJson('/sync/changes')->assertStatus(429);
});

test('les réponses de synchronisation portent les en-têtes de sécurité', function () {
    $this->getJson('/sync/changes')
        ->assertUnauthorized()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

// ------------------------------------------------------------------ Entrées et sorties

test('une entrée envoyée par un appareil est appliquée avec son identifiant, son appareil et sa date réelle', function () {
    [$device, $token, $user] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);
    $at = now()->subDays(2)->setTime(8, 30, 0);
    $op = syncOp(['product_id' => $product->id, 'quantity' => 5, 'comment' => 'Livraison hors ligne', 'occurred_at' => $at->toIso8601String()]);

    syncPost($token, [$op])
        ->assertOk()
        ->assertJsonPath('blocked', null)
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.uuid', $op['uuid'])
        ->assertJsonPath('results.0.quantity_after', 15);

    $movement = StockMovement::firstOrFail();

    expect($product->fresh()->quantity)->toBe(15)
        ->and($movement->uuid)->toBe($op['uuid'])
        ->and($movement->device_id)->toBe($device->id)
        ->and($movement->user_id)->toBe($user->id)
        ->and($movement->reason)->toBe('Livraison hors ligne')
        ->and($movement->occurred_at->toDateTimeString())->toBe($at->toDateTimeString())
        ->and($movement->recorded_at->greaterThan($movement->occurred_at))->toBeTrue();

    expect(SyncOperation::firstOrFail()->movement_id)->toBe($movement->id);
});

test('une sortie envoyée par un appareil est appliquée', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);

    syncPost($token, [syncExit($product, 3)])
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.quantity_after', 7);

    expect($product->fresh()->quantity)->toBe(7);
});

test('une sortie supérieure au stock est un conflit : stock inchangé, aucun mouvement', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 5]);

    syncPost($token, [syncExit($product, 8)])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'conflict')
        ->assertJsonPath('results.0.movement_id', null);

    $operation = SyncOperation::firstOrFail();

    expect($product->fresh()->quantity)->toBe(5)
        ->and(StockMovement::count())->toBe(0)
        ->and($operation->status->value)->toBe('conflict')
        ->and($operation->reason)->toContain('Stock insuffisant')
        ->and($operation->product_id)->toBe($product->id);
});

// ------------------------------------------------------------------ Idempotence

test('renvoyer une opération déjà reçue donne le résultat initial sans rien modifier', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);
    $op = syncExit($product, 3);

    syncPost($token, [$op])->assertJsonPath('results.0.status', 'applied');

    syncPost($token, [$op])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'duplicate')
        ->assertJsonPath('results.0.initial_status', 'applied')
        ->assertJsonPath('results.0.quantity_after', 7);

    expect($product->fresh()->quantity)->toBe(7)
        ->and(StockMovement::count())->toBe(1)
        ->and(SyncOperation::count())->toBe(1);
});

test('un doublon dans le même lot est ignoré', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);
    $op = syncExit($product, 3);

    $response = syncPost($token, [$op, $op]);

    expect($response->json('results.*.status'))->toBe(['applied', 'duplicate'])
        ->and($product->fresh()->quantity)->toBe(7)
        ->and(StockMovement::count())->toBe(1);
});

test('un conflit renvoyé plus tard reste un conflit, même si le stock a été reconstitué', function () {
    [, $token, $user] = syncDevice();
    $product = Product::factory()->create(['quantity' => 5]);
    $op = syncExit($product, 8);

    syncPost($token, [$op])->assertJsonPath('results.0.status', 'conflict');

    app(StockMovementService::class)->recordEntry($product, 20, $user);

    syncPost($token, [$op])
        ->assertJsonPath('results.0.status', 'duplicate')
        ->assertJsonPath('results.0.initial_status', 'conflict');

    expect($product->fresh()->quantity)->toBe(25);   // l'opération n'a jamais été rejouée
});

test("un appareil ne peut pas réutiliser l'identifiant d'une opération d'un autre appareil", function () {
    [, $tokenA] = syncDevice();
    [, $tokenB] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);
    $op = syncExit($product, 3);

    syncPost($tokenA, [$op])->assertJsonPath('results.0.status', 'applied');

    syncPost($tokenB, [$op])
        ->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.movement_id', null);

    expect($product->fresh()->quantity)->toBe(7)->and(StockMovement::count())->toBe(1);
});

// ------------------------------------------------------------------ Ordre

test("les opérations d'un appareil sont traitées dans l'ordre de leur séquence", function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 2]);

    $exit = syncExit($product, 6, ['seq' => 2]);
    $entry = syncOp(['seq' => 1, 'product_id' => $product->id, 'quantity' => 10]);

    // Envoyées dans le désordre : sans tri, la sortie échouerait faute de stock.
    $response = syncPost($token, [$exit, $entry]);

    expect($response->json('results.*.uuid'))->toBe([$entry['uuid'], $exit['uuid']])
        ->and($response->json('results.*.status'))->toBe(['applied', 'applied'])
        ->and($product->fresh()->quantity)->toBe(6);
});

// ------------------------------------------------------------------ Validation

test('une opération invalide est rejetée, enregistrée, et son renvoi donne le même résultat', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);

    $invalid = [
        ['type' => 'inconnu'], ['type' => null],
        ['quantity' => 0], ['quantity' => -3], ['quantity' => 2.5], ['quantity' => 'abc'], ['quantity' => null],
        ['reason_code' => 'sale'], ['reason_code' => null],
        ['occurred_at' => null], ['occurred_at' => 'pas une date'],
        ['product_id' => null],
    ];

    $operations = array_map(
        fn (array $override) => syncOp(array_merge(['product_id' => $product->id], $override)),
        $invalid
    );

    $results = syncPost($token, $operations)->json('results');

    foreach ($results as $result) {
        expect($result['status'])->toBe('rejected')->and($result['reason'])->toStartWith('Opération invalide');
    }

    expect(SyncOperation::count())->toBe(count($invalid))
        ->and(StockMovement::count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(10);

    syncPost($token, [$operations[0]])
        ->assertJsonPath('results.0.status', 'duplicate')
        ->assertJsonPath('results.0.initial_status', 'rejected');
});

test("un ajustement sans justification est rejeté", function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);

    syncPost($token, [syncAdjustment($product, 7, ['comment' => null])])
        ->assertJsonPath('results.0.status', 'rejected');
});

test("une opération sans identifiant valide est rejetée et n'est pas enregistrée", function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);

    syncPost($token, [syncExit($product, 3, ['uuid' => 'pas-un-uuid']), 'texte', []])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.uuid', null);

    expect(SyncOperation::count())->toBe(0)->and($product->fresh()->quantity)->toBe(10);
});

test('un produit introuvable, inactif ou une date future sont rejetés avec leur raison', function () {
    [, $token] = syncDevice();
    $inactive = Product::factory()->inactive()->create(['quantity' => 10]);
    $active = Product::factory()->create(['quantity' => 10]);

    $response = syncPost($token, [
        syncOp(['product_id' => 999999]),
        syncOp(['product_id' => $inactive->id]),
        syncOp(['product_id' => $active->id, 'occurred_at' => now()->addDay()->toIso8601String()]),
    ]);

    expect($response->json('results.*.status'))->toBe(['rejected', 'rejected', 'rejected']);
    expect($response->json('results.0.reason'))->toContain('introuvable')
        ->and($response->json('results.1.reason'))->toContain('inactif')
        ->and($response->json('results.2.reason'))->toContain('futur');

    expect(StockMovement::count())->toBe(0);
});

test('la taille d\'un lot est limitée', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 1000]);

    syncPost($token, [])->assertStatus(422)->assertJsonValidationErrors('operations');
    $this->withToken($token)->postJson('/sync/operations', [])->assertStatus(422);
    syncPost($token, array_map(fn () => syncOp(['product_id' => $product->id]), range(1, 101)))
        ->assertStatus(422)->assertJsonValidationErrors('operations');

    syncPost($token, array_map(fn () => syncOp(['product_id' => $product->id, 'quantity' => 1]), range(1, 100)))
        ->assertOk();

    expect($product->fresh()->quantity)->toBe(1100);
});

// ------------------------------------------------------------------ Ajustements hors ligne

test('un ajustement hors ligne est en attente de validation : jamais appliqué automatiquement', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);

    syncPost($token, [syncAdjustment($product, 7)])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'pending_validation')
        ->assertJsonPath('results.0.movement_id', null);

    $operation = SyncOperation::firstOrFail();

    expect($product->fresh()->quantity)->toBe(10)
        ->and(StockMovement::count())->toBe(0)
        ->and($operation->status->value)->toBe('pending_validation')
        ->and($operation->payload['counted_quantity'])->toBe(7)
        ->and($operation->product_id)->toBe($product->id);
});

test("l'inventaire d'un produit à lots exige le lot compté", function () {
    [, $token, $user] = syncDevice();
    $product = Product::factory()->tracked(false)->create();
    app(StockMovementService::class)->recordEntry($product, 10, $user, lotNumber: 'A', expiresAt: now()->addDays(30));
    $lotId = StockLot::firstOrFail()->id;

    $response = syncPost($token, [syncAdjustment($product, 7), syncAdjustment($product, 7, ['lot_id' => $lotId])]);

    expect($response->json('results.*.status'))->toBe(['rejected', 'pending_validation'])
        ->and($response->json('results.0.reason'))->toContain('lot par lot')
        ->and($product->fresh()->quantity)->toBe(10);
});

// ------------------------------------------------------------------ Lots

test('une entrée avec lot crée le lot comme en ligne', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->tracked()->create();
    $expiry = now()->addDays(30)->format('Y-m-d');

    syncPost($token, [syncOp([
        'product_id' => $product->id, 'quantity' => 12, 'lot_number' => 'ndv-2611', 'expires_at' => $expiry,
    ])])->assertJsonPath('results.0.status', 'applied');

    $lot = StockLot::firstOrFail();

    expect($lot->lot_number)->toBe('NDV-2611')
        ->and($lot->quantity_remaining)->toBe(12)
        ->and($lot->expires_at->toDateString())->toBe($expiry)
        ->and($product->fresh()->quantity)->toBe(12);
});

test('un lot demandé devenu indisponible déclenche un recalcul FEFO signalé', function () {
    [, $token, $user] = syncDevice();
    $product = Product::factory()->tracked(false)->create();
    $service = app(StockMovementService::class);
    $service->recordEntry($product, 5, $user, lotNumber: 'A', expiresAt: now()->addDays(10));
    $service->recordEntry($product, 5, $user, lotNumber: 'B', expiresAt: now()->addDays(20));
    $lotA = StockLot::where('lot_number', 'A')->firstOrFail();

    // Lot inexistant.
    $first = syncPost($token, [syncExit($product, 4, ['lot_id' => 999999])]);
    expect($first->json('results.0.status'))->toBe('applied')
        ->and($first->json('results.0.notes.0'))->toContain('FEFO');

    // Lot A ne contient plus que 1 unité (4 sorties du lot A par FEFO) : 7 demandées sur ce lot.
    $second = syncPost($token, [syncExit($product, 5, ['lot_id' => $lotA->id])]);
    expect($second->json('results.0.status'))->toBe('applied')
        ->and($second->json('results.0.notes.0'))->toContain('FEFO');

    expect(StockLot::orderBy('lot_number')->pluck('quantity_remaining', 'lot_number')->all())->toBe(['A' => 0, 'B' => 1])
        ->and($product->fresh()->quantity)->toBe(1);
});

test('un produit sans lots ignore le lot indiqué par un appareil', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);

    $response = syncPost($token, [syncExit($product, 3, ['lot_id' => 42])]);

    expect($response->json('results.0.status'))->toBe('applied')
        ->and($response->json('results.0.notes'))->toBe([])
        ->and($product->fresh()->quantity)->toBe(7);
});

// ------------------------------------------------------------------ Panne inattendue

test("une panne inattendue ne laisse rien d'enregistré et l'appareil peut réessayer", function () {
    [, $token] = syncDevice();
    // Produit volontairement incohérent : 10 en stock, aucun lot. L'invariant rompu lève une LogicException.
    $product = Product::factory()->tracked()->create(['quantity' => 10]);
    $op = syncOp([
        'product_id' => $product->id, 'quantity' => 5, 'lot_number' => 'A', 'expires_at' => now()->addDays(10)->format('Y-m-d'),
    ]);

    syncPost($token, [$op])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'error');

    expect(SyncOperation::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and(StockLot::count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(10);
});

// ------------------------------------------------------------------ Catalogue

test('la première synchronisation renvoie le catalogue complet, sans prix ni donnée sensible', function () {
    [, $token] = syncDevice();
    $category = Category::factory()->create(['name' => 'Aliments']);
    $supplier = Supplier::factory()->create(['name' => 'Agro Plus', 'phone' => '+22670123456', 'lead_time_days' => 5]);
    $product = Product::factory()->create([
        'category_id' => $category->id, 'supplier_id' => $supplier->id, 'quantity' => 12,
        'purchase_price' => '123.45', 'sale_price' => '200.00', 'minimum_stock' => 10,
    ]);

    $this->withToken($token)->getJson('/sync/changes')
        ->assertOk()
        ->assertJsonPath('full', true)
        ->assertJsonPath('products.0.sku', $product->sku)
        ->assertJsonPath('products.0.quantity', 12)
        ->assertJsonPath('products.0.minimum_stock', 10)
        ->assertJsonPath('products.0.deleted', false)
        ->assertJsonPath('categories.0.name', 'Aliments')
        ->assertJsonPath('suppliers.0.phone', '+22670123456')
        ->assertJsonPath('suppliers.0.lead_time_days', 5)
        ->assertJsonMissingPath('products.0.purchase_price')
        ->assertJsonMissingPath('products.0.sale_price')
        ->assertJsonMissingPath('users')
        ->assertJsonMissingPath('audit')
        ->assertJsonStructure([
            'cursor', 'server_time',
            'settings' => ['coverage' => ['window_days'], 'replenishment', 'dormancy_days', 'expiry_warning_days'],
        ]);
});

test('seuls les changements depuis le curseur sont renvoyés, bornes incluses', function () {
    [, $token] = syncDevice();
    $since = now()->subHour()->startOfSecond();

    $old = Product::factory()->create(['name' => 'Ancien']);
    $justBefore = Product::factory()->create(['name' => 'Juste avant']);
    $onBoundary = Product::factory()->create(['name' => 'Sur la borne']);
    $recent = Product::factory()->create(['name' => 'Récent']);

    DB::table('products')->where('id', $old->id)->update(['updated_at' => $since->copy()->subDay()->toDateTimeString()]);
    DB::table('products')->where('id', $justBefore->id)->update(['updated_at' => $since->copy()->subSecond()->toDateTimeString()]);
    DB::table('products')->where('id', $onBoundary->id)->update(['updated_at' => $since->toDateTimeString()]);

    $response = $this->withToken($token)->getJson('/sync/changes?since='.urlencode($since->toIso8601String()))
        ->assertOk()
        ->assertJsonPath('full', false);

    expect(collect($response->json('products'))->pluck('name')->sort()->values()->all())
        ->toBe(['Récent', 'Sur la borne']);
});

test('une opération appliquée apparaît dans les changements suivants', function () {
    [, $token] = syncDevice();
    $product = Product::factory()->create(['quantity' => 10]);
    $since = now()->subMinute();

    syncPost($token, [syncExit($product, 3)])->assertJsonPath('results.0.status', 'applied');

    $response = $this->withToken($token)->getJson('/sync/changes?since='.urlencode($since->toIso8601String()));

    expect(collect($response->json('products'))->firstWhere('id', $product->id)['quantity'])->toBe(7);
});

test('le curseur recule de quelques secondes pour ne rien manquer', function () {
    [, $token] = syncDevice();

    $response = $this->withToken($token)->getJson('/sync/changes')->assertOk();

    $cursor = Illuminate\Support\Carbon::parse($response->json('cursor'));
    $serverTime = Illuminate\Support\Carbon::parse($response->json('server_time'));

    expect($serverTime->diffInSeconds($cursor, true))->toBeGreaterThanOrEqual(9)->toBeLessThanOrEqual(11);
});

test('la première synchronisation ne renvoie que les lots ouverts, les suivantes tout lot modifié', function () {
    [, $token, $user] = syncDevice();
    $product = Product::factory()->tracked(false)->create();
    $service = app(StockMovementService::class);
    $service->recordEntry($product, 5, $user, lotNumber: 'OUVERT', expiresAt: now()->addDays(30));
    $service->recordEntry($product, 3, $user, lotNumber: 'VIDE', expiresAt: now()->addDays(40));
    $service->recordAdjustment($product, 0, $user, 'Lot vide', lotId: StockLot::where('lot_number', 'VIDE')->value('id'));

    $full = $this->withToken($token)->getJson('/sync/changes');
    expect(collect($full->json('lots'))->pluck('lot_number')->all())->toBe(['OUVERT']);

    $delta = $this->withToken($token)->getJson('/sync/changes?since='.urlencode(now()->subMinute()->toIso8601String()));
    expect(collect($delta->json('lots'))->pluck('lot_number')->sort()->values()->all())->toBe(['OUVERT', 'VIDE']);
});

test('un produit supprimé est signalé dans les changements mais absent de la première synchronisation', function () {
    [, $token] = syncDevice();
    $kept = Product::factory()->create(['name' => 'Conservé']);
    $deleted = Product::factory()->create(['name' => 'Supprimé']);
    $deleted->delete();

    $full = $this->withToken($token)->getJson('/sync/changes');
    expect(collect($full->json('products'))->pluck('name')->all())->toBe(['Conservé']);

    $delta = $this->withToken($token)->getJson('/sync/changes?since='.urlencode(now()->subMinute()->toIso8601String()));
    $row = collect($delta->json('products'))->firstWhere('name', 'Supprimé');

    expect($row['deleted'])->toBeTrue()->and($kept->exists)->toBeTrue();
});

test('un curseur invalide est refusé', function () {
    [, $token] = syncDevice();

    $this->withToken($token)->getJson('/sync/changes?since=pas-une-date')
        ->assertStatus(422)->assertJsonValidationErrors('since');
});