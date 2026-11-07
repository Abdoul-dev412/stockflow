<?php

use App\Enums\MovementStatus;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\SyncOperation;
use App\Models\User;
use App\Services\DailyActionsBuilder;
use App\Services\StockMovementService;
use Illuminate\Support\Str;

// ------------------------------------------------------------------ Outils

/** @return array{0: User, 1: Device, 2: Product} */
function resolutionContext(array $productAttributes = ['quantity' => 5]): array
{
    $owner = User::factory()->create(['name' => 'Awa Gestionnaire']);
    $device = Device::factory()->create(['user_id' => $owner->id, 'name' => 'Tablette Awa']);

    return [$owner, $device, Product::factory()->create($productAttributes)];
}

/** Une sortie de 8 en conflit : par défaut, le stock n'en contient que 5. */
function resolutionOp(User $owner, Device $device, Product $product, array $overrides = [], array $payload = []): SyncOperation
{
    $uuid = (string) Str::uuid();
    $occurredAt = now()->subHour()->startOfSecond();

    return SyncOperation::create($overrides + [
        'uuid' => $uuid,
        'device_id' => $device->id,
        'user_id' => $owner->id,
        'seq' => 1,
        'type' => 'exit',
        'product_id' => $product->id,
        'payload' => $payload + [
            'uuid' => $uuid, 'seq' => 1, 'type' => 'exit', 'product_id' => $product->id, 'quantity' => 8,
            'reason_code' => 'sale', 'comment' => 'Vente hors ligne', 'occurred_at' => $occurredAt->toIso8601String(),
        ],
        'occurred_at' => $occurredAt,
        'status' => MovementStatus::Conflict,
        'reason' => 'Stock insuffisant : 5 disponible(s), 8 demandé(s).',
        'received_at' => now()->subMinutes(30),
    ]);
}

function pendingAdjustmentOp(User $owner, Device $device, Product $product, int $counted, array $payload = []): SyncOperation
{
    return resolutionOp($owner, $device, $product, [
        'type' => 'adjustment',
        'status' => MovementStatus::PendingValidation,
        'reason' => 'Inventaire saisi hors ligne : en attente de validation par un administrateur.',
    ], $payload + [
        'type' => 'adjustment', 'counted_quantity' => $counted, 'comment' => 'Comptage hors ligne',
        'quantity' => null, 'reason_code' => null,
    ]);
}

function resolveSyncOp(User $actor, SyncOperation $operation, string $action, ?string $comment = null)
{
    return test()->actingAs($actor)->post(
        route('sync-operations.resolve', $operation),
        array_filter(['action' => $action, 'comment' => $comment], fn ($value) => $value !== null),
    );
}

// ------------------------------------------------------------------ Droits

test('seuls les utilisateurs autorisés résolvent un conflit', function () {
    [$owner, $device, $product] = resolutionContext();
    $operation = resolutionOp($owner, $device, $product);

    $this->post(route('sync-operations.resolve', $operation), ['action' => 'partial'])->assertRedirect(route('login'));

    // Un autre gestionnaire n'a aucun droit sur les opérations d'autrui.
    resolveSyncOp(User::factory()->create(), $operation, 'partial')->assertForbidden();
    expect($operation->fresh()->status->value)->toBe('conflict');

    // Le propriétaire, et l'administrateur, peuvent.
    resolveSyncOp($owner, $operation, 'reject', 'Erreur de saisie')->assertSessionHasNoErrors();
    expect($operation->fresh()->status->value)->toBe('rejected');

    $second = resolutionOp($owner, $device, $product);
    resolveSyncOp(User::factory()->admin()->create(), $second, 'reject', 'Décision du responsable')->assertSessionHasNoErrors();
    expect($second->fresh()->status->value)->toBe('rejected');
});

test('un gestionnaire ne peut ni valider ni refuser un inventaire, pas même le sien', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    $operation = pendingAdjustmentOp($owner, $device, $product, 7);

    resolveSyncOp($owner, $operation, 'approve')->assertForbidden();
    resolveSyncOp($owner, $operation, 'refuse', 'Non')->assertForbidden();

    expect($operation->fresh()->status->value)->toBe('pending_validation')
        ->and($product->fresh()->quantity)->toBe(10);
});

test("une action inconnue est refusée", function () {
    [$owner, $device, $product] = resolutionContext();
    $operation = resolutionOp($owner, $device, $product);

    resolveSyncOp($owner, $operation, 'detruire')->assertSessionHasErrors('action');
});

// ------------------------------------------------------------------ Rejet d'un conflit

test('un conflit rejeté ne touche pas au stock et garde une trace complète', function () {
    [$owner, $device, $product] = resolutionContext();
    $operation = resolutionOp($owner, $device, $product);

    resolveSyncOp($owner, $operation, 'reject', 'Vente annulée par le client')->assertSessionHasNoErrors();

    $fresh = $operation->fresh();

    expect($fresh->status->value)->toBe('rejected')
        ->and($fresh->reason)->toContain('Awa Gestionnaire')->toContain('Vente annulée par le client')
        ->and($fresh->resolved_by)->toBe($owner->id)
        ->and($fresh->resolved_at)->not->toBeNull()
        ->and($fresh->movement_id)->toBeNull();

    expect($product->fresh()->quantity)->toBe(5)->and(StockMovement::count())->toBe(0);

    $log = AuditLog::where('action', 'sync.conflict_resolved')->firstOrFail();

    expect($log->user_id)->toBe($owner->id)
        ->and($log->entity_type)->toBe('sync_operation')
        ->and($log->entity_id)->toBe($operation->id)
        ->and($log->details['resolution'])->toBe('rejected')
        ->and($log->details['comment'])->toBe('Vente annulée par le client')
        ->and($log->details['product'])->toBe($product->sku)
        ->and($log->details['device'])->toBe('Tablette Awa');
});

test('rejeter exige un commentaire', function () {
    [$owner, $device, $product] = resolutionContext();
    $operation = resolutionOp($owner, $device, $product);

    resolveSyncOp($owner, $operation, 'reject')->assertSessionHasErrors('comment');
    resolveSyncOp($owner, $operation, 'reject', '   ')->assertSessionHasErrors('comment');

    expect($operation->fresh()->status->value)->toBe('conflict')->and(AuditLog::count())->toBe(0);
});

test('une opération déjà traitée ne peut pas être traitée une seconde fois', function () {
    [$owner, $device, $product] = resolutionContext();
    $operation = resolutionOp($owner, $device, $product);

    resolveSyncOp($owner, $operation, 'reject', 'Premier traitement');
    resolveSyncOp($owner, $operation, 'reject', 'Second traitement')->assertSessionHasErrors('form');
    resolveSyncOp($owner, $operation, 'partial')->assertSessionHasErrors('form');

    expect(AuditLog::count())->toBe(1)->and(StockMovement::count())->toBe(0);
});

test('une action de conflit sur un inventaire (ou l\'inverse) est refusée sans effet', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    $admin = User::factory()->admin()->create();
    $adjustment = pendingAdjustmentOp($owner, $device, $product, 7);
    $conflict = resolutionOp($owner, $device, $product);

    resolveSyncOp($admin, $adjustment, 'partial')->assertSessionHasErrors('form');
    resolveSyncOp($admin, $conflict, 'approve')->assertSessionHasErrors('form');

    expect($adjustment->fresh()->status->value)->toBe('pending_validation')
        ->and($conflict->fresh()->status->value)->toBe('conflict')
        ->and($product->fresh()->quantity)->toBe(10);
});

// ------------------------------------------------------------------ Application de la quantité disponible

test("l'application partielle enregistre le stock restant avec l'identité de l'opération d'origine", function () {
    [$owner, $device, $product] = resolutionContext();
    $admin = User::factory()->admin()->create();
    $operation = resolutionOp($owner, $device, $product);

    resolveSyncOp($admin, $operation, 'partial')->assertSessionHasNoErrors();

    $movement = StockMovement::firstOrFail();

    expect($movement->quantity)->toBe(5)
        ->and($movement->uuid)->toBe($operation->uuid)
        ->and($movement->device_id)->toBe($device->id)
        ->and($movement->user_id)->toBe($owner->id)              // l'auteur du geste, pas celui qui résout
        ->and($movement->occurred_at->toDateTimeString())->toBe($operation->occurred_at->toDateTimeString())
        ->and($movement->reason)->toBe('Vente hors ligne')
        ->and($product->fresh()->quantity)->toBe(0);

    $fresh = $operation->fresh();

    expect($fresh->status->value)->toBe('applied')
        ->and($fresh->movement_id)->toBe($movement->id)
        ->and($fresh->resolved_by)->toBe($admin->id)
        ->and($fresh->notes[0])->toContain('5 sur 8');

    $log = AuditLog::where('action', 'sync.conflict_resolved')->firstOrFail();

    expect($log->details['resolution'])->toBe('partial')
        ->and($log->details['requested'])->toBe(8)
        ->and($log->details['applied'])->toBe(5);
});

test('si le stock a été reconstitué entre-temps, la quantité demandée est appliquée en entier', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 20]);
    $operation = resolutionOp($owner, $device, $product);

    resolveSyncOp($owner, $operation, 'partial')->assertSessionHasNoErrors();

    expect(StockMovement::firstOrFail()->quantity)->toBe(8)
        ->and($product->fresh()->quantity)->toBe(12)
        ->and($operation->fresh()->notes)->toBeNull();

    expect(AuditLog::first()->details['resolution'])->toBe('applied_in_full');
});

test('sans aucun stock utilisable, rien n\'est appliqué et le conflit reste ouvert', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 0]);
    $operation = resolutionOp($owner, $device, $product);

    resolveSyncOp($owner, $operation, 'partial')->assertSessionHasErrors('form');

    expect($operation->fresh()->status->value)->toBe('conflict')
        ->and(StockMovement::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

test("l'application partielle d'un produit à lots ignore les lots expirés", function () {
    [$owner, $device] = resolutionContext();
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->tracked(false)->create(['minimum_stock' => 0]);
    $service = app(StockMovementService::class);

    $service->recordEntry($product, 3, $owner, lotNumber: 'A', expiresAt: now()->addDays(10));
    $service->recordEntry($product, 4, $owner, lotNumber: 'X', expiresAt: now()->addDays(5));
    StockLot::where('lot_number', 'X')->update(['expires_at' => today()->subDay()->toDateString()]);

    $operation = resolutionOp($owner, $device, $product);

    resolveSyncOp($admin, $operation, 'partial')->assertSessionHasNoErrors();

    expect(StockMovement::orderByDesc('id')->first()->quantity)->toBe(3)
        ->and(StockLot::orderBy('lot_number')->pluck('quantity_remaining', 'lot_number')->all())->toBe(['A' => 0, 'X' => 4])
        ->and($product->fresh()->quantity)->toBe(4);
});

test("les opérations d'un compte désactivé ne peuvent plus être appliquées : l'administrateur doit les rejeter", function () {
    [$owner, $device, $product] = resolutionContext();
    $admin = User::factory()->admin()->create();
    $operation = resolutionOp($owner, $device, $product);

    // is_active est protégé contre l'affectation de masse : update() l'ignorerait en silence.
    $owner->forceFill(['is_active' => false])->save();

    resolveSyncOp($admin, $operation, 'partial')->assertSessionHasErrors('form');

    expect(session('errors')->get('form')[0])->toContain('désactivé');
    expect($operation->fresh()->status->value)->toBe('conflict')->and($product->fresh()->quantity)->toBe(5);

    resolveSyncOp($admin, $operation, 'reject', 'Compte désactivé')->assertSessionHasNoErrors();
    expect($operation->fresh()->status->value)->toBe('rejected');
});

// ------------------------------------------------------------------ Rejouer

test('rejouer un conflit toujours impossible le laisse ouvert avec sa raison à jour', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 6]);
    $operation = resolutionOp($owner, $device, $product);

    resolveSyncOp($owner, $operation, 'replay')->assertSessionHasErrors('form');

    expect(session('errors')->get('form')[0])->toContain('Toujours en conflit');

    $fresh = $operation->fresh();

    expect($fresh->status->value)->toBe('conflict')
        ->and($fresh->reason)->toContain('6 disponible')
        ->and(StockMovement::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

test('après une entrée de stock, rejouer applique la sortie telle que demandée', function () {
    [$owner, $device, $product] = resolutionContext();
    $operation = resolutionOp($owner, $device, $product);

    app(StockMovementService::class)->recordEntry($product, 10, $owner);   // stock : 15

    resolveSyncOp($owner, $operation, 'replay')->assertSessionHasNoErrors();

    $fresh = $operation->fresh();

    expect($fresh->status->value)->toBe('applied')
        ->and($fresh->notes[0])->toContain('Rejouée')
        ->and($product->fresh()->quantity)->toBe(7);

    $movement = StockMovement::where('uuid', $operation->uuid)->firstOrFail();

    expect($movement->quantity)->toBe(8)->and($movement->device_id)->toBe($device->id);
    expect(AuditLog::where('action', 'sync.conflict_resolved')->first()->details['resolution'])->toBe('replayed');
});

test('rejouer sur un produit devenu inactif échoue sans rien changer', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 20]);
    $operation = resolutionOp($owner, $device, $product);
    $product->update(['is_active' => false]);

    resolveSyncOp($owner, $operation, 'replay')->assertSessionHasErrors('form');

    expect(session('errors')->get('form')[0])->toContain('inactif');
    expect($operation->fresh()->status->value)->toBe('conflict')->and($product->fresh()->quantity)->toBe(20);
});

// ------------------------------------------------------------------ Validation des inventaires

test('confirmer un inventaire crée le mouvement avec l\'identité de l\'opération d\'origine', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    $admin = User::factory()->admin()->create();
    $operation = pendingAdjustmentOp($owner, $device, $product, 7);

    resolveSyncOp($admin, $operation, 'approve')->assertSessionHasNoErrors();

    $movement = StockMovement::firstOrFail();

    expect($movement->type->value)->toBe('adjustment')
        ->and($movement->quantity)->toBe(3)
        ->and($movement->signedQuantity())->toBe(-3)
        ->and($movement->uuid)->toBe($operation->uuid)
        ->and($movement->user_id)->toBe($owner->id)
        ->and($movement->device_id)->toBe($device->id)
        ->and($movement->occurred_at->toDateTimeString())->toBe($operation->occurred_at->toDateTimeString())
        ->and($movement->reason)->toBe('Comptage hors ligne')
        ->and($product->fresh()->quantity)->toBe(7);

    $fresh = $operation->fresh();

    expect($fresh->status->value)->toBe('applied')
        ->and($fresh->movement_id)->toBe($movement->id)
        ->and($fresh->resolved_by)->toBe($admin->id);

    // L'ajustement est tracé par le service, et la décision par la résolution.
    expect(AuditLog::where('action', 'stock.adjustment')->count())->toBe(1);

    $decision = AuditLog::where('action', 'sync.adjustment_validated')->firstOrFail();

    expect($decision->user_id)->toBe($admin->id)
        ->and($decision->details['decision'])->toBe('approved')
        ->and($decision->details['counted'])->toBe(7);
});

test('le comptage s\'applique au stock actuel, pas à celui du moment de la saisie', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    $operation = pendingAdjustmentOp($owner, $device, $product, 7);

    $product->forceFill(['quantity' => 12])->save();   // le stock a évolué depuis le comptage

    resolveSyncOp(User::factory()->admin()->create(), $operation, 'approve')->assertSessionHasNoErrors();

    expect(StockMovement::firstOrFail()->quantity)->toBe(5)->and($product->fresh()->quantity)->toBe(7);
});

test("l'inventaire d'un lot corrige ce lot", function () {
    [$owner, $device] = resolutionContext();
    $product = Product::factory()->tracked(false)->create(['minimum_stock' => 0]);
    app(StockMovementService::class)->recordEntry($product, 6, $owner, lotNumber: 'A', expiresAt: now()->addDays(10));
    $lot = StockLot::firstOrFail();

    $operation = pendingAdjustmentOp($owner, $device, $product, 4, ['lot_id' => $lot->id]);

    resolveSyncOp(User::factory()->admin()->create(), $operation, 'approve')->assertSessionHasNoErrors();

    expect($lot->fresh()->quantity_remaining)->toBe(4)->and($product->fresh()->quantity)->toBe(4);
});

test('un comptage égal au stock actuel ou un produit inactif ne peuvent pas être validés', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    $admin = User::factory()->admin()->create();

    $same = pendingAdjustmentOp($owner, $device, $product, 10);
    resolveSyncOp($admin, $same, 'approve')->assertSessionHasErrors('form');
    expect(session('errors')->get('form')[0])->toContain('aucun ajustement');

    $inactive = pendingAdjustmentOp($owner, $device, $product, 7);
    $product->update(['is_active' => false]);
    resolveSyncOp($admin, $inactive, 'approve')->assertSessionHasErrors('form');

    expect($same->fresh()->status->value)->toBe('pending_validation')
        ->and($inactive->fresh()->status->value)->toBe('pending_validation')
        ->and(StockMovement::count())->toBe(0);
});

test('refuser un inventaire exige un commentaire et ne touche pas au stock', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    $admin = User::factory()->admin()->create();
    $operation = pendingAdjustmentOp($owner, $device, $product, 7);

    resolveSyncOp($admin, $operation, 'refuse')->assertSessionHasErrors('comment');
    expect($operation->fresh()->status->value)->toBe('pending_validation');

    resolveSyncOp($admin, $operation, 'refuse', 'Comptage douteux, à refaire')->assertSessionHasNoErrors();

    $fresh = $operation->fresh();

    expect($fresh->status->value)->toBe('rejected')
        ->and($fresh->reason)->toContain('Comptage douteux')
        ->and($product->fresh()->quantity)->toBe(10)
        ->and(StockMovement::count())->toBe(0);

    $log = AuditLog::where('action', 'sync.adjustment_validated')->firstOrFail();

    expect($log->details['decision'])->toBe('refused')->and($log->details['comment'])->toBe('Comptage douteux, à refaire');
});

test('un inventaire ne peut pas être validé deux fois', function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    $admin = User::factory()->admin()->create();
    $operation = pendingAdjustmentOp($owner, $device, $product, 7);

    resolveSyncOp($admin, $operation, 'approve')->assertSessionHasNoErrors();
    resolveSyncOp($admin, $operation, 'approve')->assertSessionHasErrors('form');

    expect(StockMovement::count())->toBe(1)->and($product->fresh()->quantity)->toBe(7);
});

// ------------------------------------------------------------------ Écran

test('chacun voit ses opérations à traiter, l\'administrateur voit tout', function () {
    [$awa, $deviceA, $productA] = resolutionContext(['name' => 'Produit de Awa', 'quantity' => 5]);
    [$bruno, $deviceB, $productB] = resolutionContext(['name' => 'Produit de Bruno', 'quantity' => 5]);

    resolutionOp($awa, $deviceA, $productA);
    resolutionOp($bruno, $deviceB, $productB);
    resolutionOp($awa, $deviceA, $productA, ['status' => MovementStatus::Applied, 'reason' => null]);

    $this->actingAs($awa)->get(route('sync-operations.index'))
        ->assertOk()
        ->assertSee('Produit de Awa')->assertDontSee('Produit de Bruno')
        ->assertViewHas('operations', fn ($operations) => $operations->total() === 1);

    $this->actingAs(User::factory()->admin()->create())->get(route('sync-operations.index'))
        ->assertSee('Produit de Awa')->assertSee('Produit de Bruno');

    $this->actingAs($awa)->get(route('sync-operations.index', ['status' => 'all']))
        ->assertViewHas('operations', fn ($operations) => $operations->total() === 2);

    $this->actingAs($awa)->get(route('sync-operations.index', ['status' => 'applied']))
        ->assertViewHas('operations', fn ($operations) => $operations->total() === 1);
});

test("les boutons dépendent des droits : le propriétaire résout ses conflits, seul l'administrateur valide", function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    $conflict = resolutionOp($owner, $device, $product);
    $pending = pendingAdjustmentOp($owner, $device, $product, 7);

    $this->actingAs($owner)->get(route('sync-operations.index'))
        ->assertSee(route('sync-operations.resolve', $conflict), false)
        ->assertSee('Appliquer la quantité disponible')
        ->assertDontSee(route('sync-operations.resolve', $pending), false)
        ->assertSee('En attente de validation par un administrateur');

    $this->actingAs(User::factory()->admin()->create())->get(route('sync-operations.index'))
        ->assertSee(route('sync-operations.resolve', $pending), false)
        ->assertSee('Confirmer');
});

test("l'administrateur est averti des mouvements survenus depuis le comptage", function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    pendingAdjustmentOp($owner, $device, $product, 7);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('sync-operations.index'))->assertDontSee('mouvement(s) enregistré(s)');

    app(StockMovementService::class)->recordEntry($product, 2, $owner);

    $this->actingAs($admin)->get(route('sync-operations.index'))
        ->assertSee('1 mouvement(s) enregistré(s) sur ce produit depuis le comptage');
});

test('les filtres invalides sont refusés et la liste est paginée', function () {
    $admin = User::factory()->admin()->create();
    [$owner, $device, $product] = resolutionContext();

    $this->actingAs($admin)->get(route('sync-operations.index', ['status' => 'inconnu']))->assertSessionHasErrors('status');
    $this->actingAs($admin)->get(route('sync-operations.index', ['device' => 'abc']))->assertSessionHasErrors('device');

    foreach (range(1, 25) as $i) {
        resolutionOp($owner, $device, $product);
    }

    $this->actingAs($admin)->get(route('sync-operations.index'))
        ->assertViewHas('operations', fn ($operations) => $operations->count() === 20 && $operations->total() === 25);
});

test("le contenu saisi sur l'appareil est échappé à l'affichage", function () {
    [$owner, $device, $product] = resolutionContext(['quantity' => 10]);
    pendingAdjustmentOp($owner, $device, $product, 7, ['comment' => '<script>alert(1)</script>']);

    $this->actingAs(User::factory()->admin()->create())->get(route('sync-operations.index'))
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('le lien Synchronisation est présent dans la navigation', function () {
    $this->actingAs(User::factory()->create())->get(route('profile.edit'))
        ->assertSee(route('sync-operations.index'), false);
});

// ------------------------------------------------------------------ Actions du jour

test('les conflits et les inventaires à valider rejoignent les actions du jour', function () {
    [$awa, $deviceA, $productA] = resolutionContext(['quantity' => 5]);
    [$bruno, $deviceB, $productB] = resolutionContext(['quantity' => 5]);
    $admin = User::factory()->admin()->create();

    resolutionOp($awa, $deviceA, $productA);
    resolutionOp($awa, $deviceA, $productA);
    resolutionOp($bruno, $deviceB, $productB);
    pendingAdjustmentOp($bruno, $deviceB, $productB, 3);

    $builder = app(DailyActionsBuilder::class);

    $forAwa = $builder->forUser($awa)->firstWhere('key', 'sync_conflicts');
    expect($forAwa->count)->toBe(2)
        ->and($forAwa->url)->toBe(route('sync-operations.index', ['status' => 'conflict']))
        ->and($forAwa->items[0]['label'])->toContain($productA->sku)->toContain('Stock insuffisant');
    expect($builder->forUser($awa)->pluck('key')->all())->not->toContain('sync_adjustments');

    $forAdmin = $builder->forUser($admin);
    expect($forAdmin->firstWhere('key', 'sync_conflicts')->count)->toBe(3)
        ->and($forAdmin->firstWhere('key', 'sync_adjustments')->count)->toBe(1)
        ->and($forAdmin->firstWhere('key', 'sync_adjustments')->url)
        ->toBe(route('sync-operations.index', ['status' => 'pending_validation']))
        ->and($forAdmin->firstWhere('key', 'sync_adjustments')->items[0]['label'])->toContain($productB->sku)->toContain('3');
});

test('une opération résolue disparaît des actions du jour', function () {
    [$owner, $device, $product] = resolutionContext();
    $operation = resolutionOp($owner, $device, $product);

    expect(app(DailyActionsBuilder::class)->forUser($owner)->pluck('key')->all())->toContain('sync_conflicts');

    resolveSyncOp($owner, $operation, 'reject', 'Erreur');

    expect(app(DailyActionsBuilder::class)->forUser($owner)->pluck('key')->all())->not->toContain('sync_conflicts');
});

// ------------------------------------------------------------------ L'appareil apprend le résultat

test("l'appareil reçoit le sort de ses opérations ouvertes, puis de celles résolues", function () {
    [$owner, $device, $product] = resolutionContext();
    $token = 'sf_'.Str::random(40);
    $device->forceFill(['token_hash' => hash('sha256', $token)])->save();

    $otherDevice = Device::factory()->create(['user_id' => $owner->id]);

    $conflict = resolutionOp($owner, $device, $product);
    $pending = pendingAdjustmentOp($owner, $device, $product, 3);
    resolutionOp($owner, $device, $product, ['status' => MovementStatus::Applied, 'reason' => null]);
    resolutionOp($owner, $otherDevice, $product);                  // opération d'un autre appareil

    // Première synchronisation : seulement les opérations ouvertes de cet appareil.
    $full = $this->withToken($token)->getJson('/sync/changes')->assertOk();

    expect(collect($full->json('operations'))->pluck('uuid')->sort()->values()->all())
        ->toBe(collect([$conflict->uuid, $pending->uuid])->sort()->values()->all());

    // Résolution, puis synchronisation depuis un curseur antérieur.
    $since = now()->subMinute();
    resolveSyncOp($owner, $conflict, 'reject', 'Erreur de saisie');

    $delta = $this->withToken($token)->getJson('/sync/changes?since='.urlencode($since->toIso8601String()))->assertOk();
    $operations = collect($delta->json('operations'));

    expect($operations->pluck('uuid')->all())->toBe([$conflict->uuid])
        ->and($operations->first()['status'])->toBe('rejected')
        ->and($operations->first()['reason'])->toContain('Erreur de saisie')
        ->and($operations->first()['resolved_at'])->not->toBeNull();
});

// ------------------------------------------------------------------ Historique par appareil

test("l'historique se filtre par appareil et indique l'appareil d'origine", function () {
    $user = User::factory()->create();
    $deviceA = Device::factory()->create(['user_id' => $user->id, 'name' => 'Tablette A']);
    $deviceB = Device::factory()->create(['user_id' => $user->id, 'name' => 'Tablette B']);
    $service = app(StockMovementService::class);

    $a = Product::factory()->create(['name' => 'Produit via A', 'quantity' => 0]);
    $b = Product::factory()->create(['name' => 'Produit via B', 'quantity' => 0]);
    $c = Product::factory()->create(['name' => 'Produit en ligne', 'quantity' => 0]);

    $service->from((string) Str::uuid(), $deviceA->id)->recordEntry($a, 5, $user);
    $service->from((string) Str::uuid(), $deviceB->id)->recordEntry($b, 5, $user);
    $service->recordEntry($c, 5, $user);

    $this->actingAs($user)->get(route('movements.index', ['device' => $deviceA->id]))
        ->assertOk()
        ->assertSee('Produit via A')
        ->assertDontSee('Produit via B')
        ->assertDontSee('Produit en ligne')
        ->assertSee('via Tablette A');

    $this->actingAs($user)->get(route('movements.index'))
        ->assertSee('Produit via A')->assertSee('Produit via B')->assertSee('Produit en ligne');

    $this->actingAs($user)->get(route('movements.index', ['device' => 'abc']))->assertSessionHasErrors('device');
});

test("l'export des mouvements respecte le filtre par appareil", function () {
    $admin = User::factory()->admin()->create();
    $device = Device::factory()->create(['user_id' => $admin->id]);
    $service = app(StockMovementService::class);

    $a = Product::factory()->create(['name' => 'Produit via appareil', 'quantity' => 0]);
    $b = Product::factory()->create(['name' => 'Produit en ligne', 'quantity' => 0]);

    $service->from((string) Str::uuid(), $device->id)->recordEntry($a, 5, $admin);
    $service->recordEntry($b, 5, $admin);

    $content = $this->actingAs($admin)->get(route('exports.movements', ['device' => $device->id]))->streamedContent();

    expect($content)->toContain('Produit via appareil')->not->toContain('Produit en ligne');
});