<?php

use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;

/** Crée un lot dont la péremption tombe dans $days jours (négatif : déjà expiré). */
function makeExpiryLot(Product $product, string $number, int $days, int $quantity = 5): StockLot
{
    app(StockMovementService::class)->recordEntry(
        $product, $quantity, User::factory()->create(),
        lotNumber: $number, expiresAt: now()->addDays(max($days, 0)),
    );

    $lot = StockLot::where('product_id', $product->id)->where('lot_number', $number)->firstOrFail();
    $lot->forceFill(['expires_at' => today()->addDays($days)->toDateString()])->save();

    return $lot->fresh();
}

test('un visiteur ne peut ni voir les péremptions ni retirer des lots', function () {
    $product = Product::factory()->tracked()->create();

    $this->get(route('expiries.index'))->assertRedirect(route('login'));
    $this->post(route('expiries.remove', $product))->assertRedirect(route('login'));
});

test('la page liste les lots expirés ou proches de leur péremption, sur les produits actifs', function () {
    $product = Product::factory()->tracked(false)->create();
    makeExpiryLot($product, 'LOT-EXPIRE', -2);
    makeExpiryLot($product, 'LOT-BIENTOT', 10);
    makeExpiryLot($product, 'LOT-LOIN', 100);

    $service = app(StockMovementService::class);
    $service->recordEntry($product, 4, User::factory()->create(), lotNumber: 'LOT-SANS-DATE');

    makeExpiryLot($product, 'LOT-VIDE', 3);
    $service->recordAdjustment($product, 0, User::factory()->create(), 'Vide', lotId: StockLot::where('lot_number', 'LOT-VIDE')->value('id'));

    $inactive = Product::factory()->tracked(false)->create();
    makeExpiryLot($inactive, 'LOT-INACTIF', -1);
    $inactive->update(['is_active' => false]);

    $this->actingAs(User::factory()->create())->get(route('expiries.index'))
        ->assertOk()
        ->assertSeeInOrder(['LOT-EXPIRE', 'LOT-BIENTOT'])
        ->assertDontSee('LOT-LOIN')
        ->assertDontSee('LOT-SANS-DATE')
        ->assertDontSee('LOT-VIDE')
        ->assertDontSee('LOT-INACTIF');
});

test("l'horizon de la liste est réglable et validé", function () {
    $product = Product::factory()->tracked(false)->create();
    makeExpiryLot($product, 'LOT-BIENTOT', 10);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('expiries.index', ['days' => 7]))->assertDontSee('LOT-BIENTOT');
    $this->actingAs($user)->get(route('expiries.index', ['days' => 30]))->assertSee('LOT-BIENTOT');
    $this->actingAs($user)->get(route('expiries.index', ['days' => 'abc']))->assertSessionHasErrors('days');
});

test('les compteurs de péremption sont calculés sur les lots non vides de produits actifs', function () {
    $product = Product::factory()->tracked(false)->create();
    makeExpiryLot($product, 'LOT-EXPIRE', -2, 4);
    makeExpiryLot($product, 'LOT-BIENTOT', 10, 6);
    makeExpiryLot($product, 'LOT-LOIN', 100, 8);

    expect(StockLot::expiryCounts())->toBe(['expired' => 1, 'expired_units' => 4, 'soon' => 1])
        ->and(StockLot::expiryCounts(7)['soon'])->toBe(0)
        ->and(StockLot::expiryCounts(200)['soon'])->toBe(2);
});

test('le retrait des lots expirés crée un seul mouvement de péremption réparti sur les lots expirés', function () {
    $manager = User::factory()->create();
    $product = Product::factory()->tracked(false)->create();

    makeExpiryLot($product, 'LOT-A', -3, 4);
    makeExpiryLot($product, 'LOT-B', -1, 6);
    makeExpiryLot($product, 'LOT-C', 30, 5);

    $this->actingAs($manager)
        ->from(route('products.show', $product))
        ->post(route('expiries.remove', $product))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('products.show', $product));

    $movement = StockMovement::where('reason_code', 'expiry')->firstOrFail();

    expect(StockMovement::where('reason_code', 'expiry')->count())->toBe(1)
        ->and($movement->type->value)->toBe('exit')
        ->and($movement->quantity)->toBe(10)
        ->and($movement->user_id)->toBe($manager->id)
        ->and($movement->reason)->toBe('Retrait des lots expirés')
        ->and(StockLot::orderBy('lot_number')->pluck('quantity_remaining', 'lot_number')->all())
        ->toBe(['LOT-A' => 0, 'LOT-B' => 0, 'LOT-C' => 5])
        ->and($product->fresh()->quantity)->toBe(5);
});

test("le retrait est refusé s'il n'y a aucun lot expiré", function () {
    $product = Product::factory()->tracked(false)->create();
    makeExpiryLot($product, 'LOT-OK', 30);
    $before = StockMovement::count();

    $this->actingAs(User::factory()->create())
        ->post(route('expiries.remove', $product))
        ->assertSessionHasErrors('form');

    expect(StockMovement::count())->toBe($before)->and($product->fresh()->quantity)->toBe(5);
});

test('le retrait est refusé pour un produit sans lots ou inactif', function () {
    $plain = Product::factory()->create(['quantity' => 10]);
    $inactive = Product::factory()->tracked(false)->create();
    makeExpiryLot($inactive, 'LOT-A', -2);
    $inactive->update(['is_active' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('expiries.remove', $plain))->assertSessionHasErrors('form');
    $this->actingAs($user)->post(route('expiries.remove', $inactive))->assertSessionHasErrors('form');

    expect($plain->fresh()->quantity)->toBe(10)->and($inactive->fresh()->quantity)->toBe(5);
});

test('le bouton de retrait apparaît sur la fiche et une seule fois par produit dans la liste', function () {
    $product = Product::factory()->tracked(false)->create();
    $clean = Product::factory()->tracked(false)->create();
    makeExpiryLot($product, 'LOT-A', -3);
    makeExpiryLot($product, 'LOT-B', -1);
    makeExpiryLot($clean, 'LOT-OK', 30);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('products.show', $product))
        ->assertSee(route('expiries.remove', $product), false);
    $this->actingAs($user)->get(route('products.show', $clean))
        ->assertDontSee(route('expiries.remove', $clean), false);

    $html = $this->actingAs($user)->get(route('expiries.index'))->getContent();
    expect(substr_count($html, route('expiries.remove', $product)))->toBe(1);
});

test('les péremptions apparaissent sur la page des alertes et le tableau de bord', function () {
    $product = Product::factory()->tracked(false)->create(['name' => 'Vaccin test']);
    makeExpiryLot($product, 'LOT-EXPIRE', -2, 4);
    makeExpiryLot($product, 'LOT-BIENTOT', 10);
    makeExpiryLot($product, 'LOT-LOIN', 100);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('alerts.index'))
        ->assertOk()
        ->assertSee('Péremptions à surveiller')
        ->assertSee('Lot LOT-EXPIRE')
        ->assertSee('Lot LOT-BIENTOT')
        ->assertDontSee('Lot LOT-LOIN');

    $this->actingAs($user)->get(route('dashboard'))
        ->assertViewHas('stats', fn ($stats) => $stats['expired_lots'] === 1 && $stats['expiring_lots'] === 1);
});