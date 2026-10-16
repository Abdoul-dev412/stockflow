<?php

use App\Enums\MovementReason;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;

function movementService(): StockMovementService
{
    return app(StockMovementService::class);
}

// ------------------------------------------------------------------ Accès

test('un visiteur ne peut ni voir ni créer de mouvements', function () {
    $product = Product::factory()->create();

    $this->get(route('movements.index'))->assertRedirect(route('login'));
    $this->get(route('movements.create', [$product, 'entry']))->assertRedirect(route('login'));
    $this->post(route('movements.store', [$product, 'entry']), ['quantity' => 5])
        ->assertRedirect(route('login'));
});

test("le type d'opération ne peut être que entrée, sortie ou ajustement", function () {
    $product = Product::factory()->create();
    $manager = User::factory()->create();

    $this->actingAs($manager)->get(route('movements.create', [$product, 'entry']))->assertOk();
    $this->actingAs($manager)->get("/products/{$product->id}/movements/correction/create")->assertNotFound();
    $this->actingAs($manager)->post("/products/{$product->id}/movements/correction", ['quantity' => 5])
        ->assertNotFound();

    expect(StockMovement::count())->toBe(0);
});

// ------------------------------------------------------------------ Entrées et sorties

test('un gestionnaire peut enregistrer une entrée', function () {
    $manager = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'entry']), [
            'quantity' => 5,
            'reason_code' => 'reception',
            'reason' => 'Livraison du lundi',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('products.show', $product));

    expect($product->fresh()->quantity)->toBe(15);

    $movement = StockMovement::firstOrFail();
    expect($movement->user_id)->toBe($manager->id)
        ->and($movement->type)->toBe(MovementType::Entry)
        ->and($movement->reason)->toBe('Livraison du lundi');
});

test('un gestionnaire peut enregistrer une sortie', function () {
    $product = Product::factory()->create(['quantity' => 30]);

    $this->actingAs(User::factory()->create())
        ->post(route('movements.store', [$product, 'exit']), [
            'quantity' => 12,
            'reason_code' => 'sale',
        ])
        ->assertSessionHasNoErrors();

    expect($product->fresh()->quantity)->toBe(18);
});

test('une sortie supérieure au stock est refusée avec un message clair', function () {
    $product = Product::factory()->create(['quantity' => 5]);

    $this->actingAs(User::factory()->create())
        ->post(route('movements.store', [$product, 'exit']), [
            'quantity' => 8,
            'reason_code' => 'sale',
        ])
        ->assertSessionHasErrors('quantity');

    expect($product->fresh()->quantity)->toBe(5);
    expect(StockMovement::count())->toBe(0);
});

test('les quantités invalides sont refusées', function () {
    $product = Product::factory()->create(['quantity' => 10]);
    $manager = User::factory()->create();

    foreach ([0, -3, 'abc', 1.5, ''] as $invalid) {
        $this->actingAs($manager)
            ->post(route('movements.store', [$product, 'entry']), [
                'quantity' => $invalid,
                'reason_code' => 'reception',
            ])
            ->assertSessionHasErrors('quantity');
    }

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::count())->toBe(0);
});

test('le motif doit correspondre au type de mouvement', function () {
    $product = Product::factory()->create(['quantity' => 10]);
    $manager = User::factory()->create();

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'exit']), ['quantity' => 2, 'reason_code' => 'reception'])
        ->assertSessionHasErrors('reason_code');

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'entry']), ['quantity' => 2, 'reason_code' => 'sale'])
        ->assertSessionHasErrors('reason_code');

    expect(StockMovement::count())->toBe(0);
});

test('le motif « autre » exige un commentaire', function () {
    $product = Product::factory()->create(['quantity' => 10]);
    $manager = User::factory()->create();

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'exit']), ['quantity' => 2, 'reason_code' => 'other'])
        ->assertSessionHasErrors('reason');

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'exit']), [
            'quantity' => 2, 'reason_code' => 'other', 'reason' => 'Don à une association',
        ])
        ->assertSessionHasNoErrors();

    expect($product->fresh()->quantity)->toBe(8);
});

test('un produit inactif ne reçoit pas de nouvelles opérations', function () {
    $product = Product::factory()->inactive()->create(['quantity' => 10]);
    $manager = User::factory()->create();

    $this->actingAs($manager)
        ->get(route('movements.create', [$product, 'entry']))
        ->assertRedirect(route('products.show', $product))
        ->assertSessionHasErrors('form');

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'entry']), ['quantity' => 5, 'reason_code' => 'reception'])
        ->assertSessionHasErrors('form');

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::count())->toBe(0);
});

// ------------------------------------------------------------------ Ajustements

test('un gestionnaire peut enregistrer un ajustement justifié', function () {
    $product = Product::factory()->create(['quantity' => 40]);

    $this->actingAs(User::factory()->create())
        ->post(route('movements.store', [$product, 'adjustment']), [
            'counted_quantity' => 37,
            'reason' => 'Inventaire du magasin',
        ])
        ->assertSessionHasNoErrors();

    expect($product->fresh()->quantity)->toBe(37);
    expect(StockMovement::firstOrFail()->type)->toBe(MovementType::Adjustment);
});

test('un ajustement sans justification, sans écart ou négatif est refusé', function () {
    $product = Product::factory()->create(['quantity' => 40]);
    $manager = User::factory()->create();

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'adjustment']), ['counted_quantity' => 37])
        ->assertSessionHasErrors('reason');

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'adjustment']), [
            'counted_quantity' => 40, 'reason' => 'Comptage',
        ])
        ->assertSessionHasErrors('form');

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'adjustment']), [
            'counted_quantity' => -2, 'reason' => 'Comptage',
        ])
        ->assertSessionHasErrors('counted_quantity');

    expect($product->fresh()->quantity)->toBe(40);
    expect(StockMovement::count())->toBe(0);
});

// ------------------------------------------------------------------ Corrections

test('un gestionnaire ne peut pas corriger un mouvement', function () {
    $manager = User::factory()->create();
    $entry = movementService()->recordEntry(Product::factory()->create(['quantity' => 10]), 5, $manager);

    $this->actingAs($manager)->get(route('movements.correct.create', $entry))->assertForbidden();
    $this->actingAs($manager)
        ->post(route('movements.correct.store', $entry), ['reason' => 'Erreur'])
        ->assertForbidden();

    expect(StockMovement::where('type', MovementType::Correction)->count())->toBe(0);
});

test('un administrateur peut corriger un mouvement avec une justification', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['quantity' => 10]);
    $entry = movementService()->recordEntry($product, 5, $admin);

    $this->actingAs($admin)->get(route('movements.correct.create', $entry))
        ->assertOk()->assertSee($product->name);

    $this->actingAs($admin)
        ->post(route('movements.correct.store', $entry), ['reason' => 'Livraison saisie deux fois'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('movements.index'));

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::where('corrects_movement_id', $entry->id)->count())->toBe(1);
});

test('une correction sans justification est refusée', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['quantity' => 10]);
    $entry = movementService()->recordEntry($product, 5, $admin);

    $this->actingAs($admin)
        ->post(route('movements.correct.store', $entry), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($product->fresh()->quantity)->toBe(15);
});

test('un mouvement déjà corrigé ne peut pas être corrigé une seconde fois', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['quantity' => 10]);
    $entry = movementService()->recordEntry($product, 5, $admin);
    movementService()->recordCorrection($entry, $admin, 'Première correction');

    $this->actingAs($admin)->get(route('movements.correct.create', $entry))
        ->assertRedirect(route('movements.index'))
        ->assertSessionHasErrors('form');

    $this->actingAs($admin)
        ->post(route('movements.correct.store', $entry), ['reason' => 'Deuxième tentative'])
        ->assertSessionHasErrors('form');

    expect(StockMovement::where('corrects_movement_id', $entry->id)->count())->toBe(1);
    expect($product->fresh()->quantity)->toBe(10);
});

// ------------------------------------------------------------------ Historique

test('un gestionnaire peut consulter l\'historique mais ne voit pas le lien de correction', function () {
    $admin = User::factory()->admin()->create();
    movementService()->recordEntry(Product::factory()->create(['name' => 'Aliment ponte']), 5, $admin);

    $this->actingAs(User::factory()->create())
        ->get(route('movements.index'))
        ->assertOk()
        ->assertSee('Aliment ponte')
        ->assertDontSee('Corriger');

    $this->actingAs($admin)
        ->get(route('movements.index'))
        ->assertSee('Corriger');
});

test("l'historique se filtre par produit, type, utilisateur et période", function () {
    $alice = User::factory()->admin()->create(['name' => 'Alice Admin']);
    $bruno = User::factory()->create(['name' => 'Bruno Gestionnaire']);

    $ponte = Product::factory()->create(['name' => 'Aliment ponte', 'sku' => 'ALI-010', 'quantity' => 50]);
    $vaccin = Product::factory()->create(['name' => 'Vaccin Newcastle', 'sku' => 'VAC-010', 'quantity' => 50]);

    movementService()->recordEntry($ponte, 5, $alice, occurredAt: now()->subDays(10));
    movementService()->recordExit($vaccin, 3, $bruno, MovementReason::Sale, occurredAt: now());

    $viewer = User::factory()->create();

    // Recherche par produit (nom ou SKU)
    $this->actingAs($viewer)->get(route('movements.index', ['search' => 'ponte']))
        ->assertSee('Aliment ponte')->assertDontSee('Vaccin Newcastle');
    $this->actingAs($viewer)->get(route('movements.index', ['search' => 'VAC-010']))
        ->assertSee('Vaccin Newcastle')->assertDontSee('Aliment ponte');

    // Filtre par produit (identifiant)
    $this->actingAs($viewer)->get(route('movements.index', ['product' => $vaccin->id]))
        ->assertSee('Vaccin Newcastle')->assertDontSee('Aliment ponte');

    // Filtre par type
    $this->actingAs($viewer)->get(route('movements.index', ['type' => 'exit']))
        ->assertSee('Vaccin Newcastle')->assertDontSee('Aliment ponte');

    // Filtre par utilisateur
    $this->actingAs($viewer)->get(route('movements.index', ['user' => $alice->id]))
        ->assertSee('Aliment ponte')->assertDontSee('Vaccin Newcastle');

    // Filtre par période
    $this->actingAs($viewer)->get(route('movements.index', ['from' => now()->subDays(3)->format('Y-m-d')]))
        ->assertSee('Vaccin Newcastle')->assertDontSee('Aliment ponte');
    $this->actingAs($viewer)->get(route('movements.index', ['to' => now()->subDays(5)->format('Y-m-d')]))
        ->assertSee('Aliment ponte')->assertDontSee('Vaccin Newcastle');
});

test('une date invalide ou une période inversée est refusée', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(route('movements.index', ['from' => 'pas-une-date']))
        ->assertSessionHasErrors('from');

    $this->actingAs($viewer)->get(route('movements.index', ['from' => '2026-10-10', 'to' => '2026-10-01']))
        ->assertSessionHasErrors('to');
});

test("l'historique est paginé et ne déclenche pas de requêtes en cascade", function () {
    $user = User::factory()->create();
    Product::factory()->count(30)->create(['quantity' => 0])
        ->each(fn ($product) => movementService()->recordEntry($product, 5, $user));

    DB::enableQueryLog();
    $response = $this->actingAs($user)->get(route('movements.index'));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk()
        ->assertViewHas('movements', fn ($m) => $m->count() === 25 && $m->total() === 30);

    expect($queries)->toBeLessThan(15);
});

// ------------------------------------------------------------------ Fiche produit

test('la fiche produit affiche les derniers mouvements et les boutons d\'action', function () {
    $manager = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 0]);
    movementService()->recordEntry($product, 12, $manager, comment: 'Stock initial');

    $this->actingAs($manager)
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('Derniers mouvements')
        ->assertSee('+12')
        ->assertSee(route('movements.create', [$product, 'entry']), false)
        ->assertSee(route('movements.create', [$product, 'exit']), false)
        ->assertSee(route('movements.create', [$product, 'adjustment']), false);
});

test('la fiche d\'un produit inactif ne propose plus de nouvelles opérations', function () {
    $product = Product::factory()->inactive()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertDontSee(route('movements.create', [$product, 'entry']), false);
});