<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Services\StockMovementService;

function lotProductPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => 'VAC-100',
        'name' => 'Vaccin test',
        'category_id' => Category::factory()->create()->id,
        'unit' => 'boîte',
        'purchase_price' => '12000.00',
        'minimum_stock' => 5,
    ], $overrides);
}

test('un produit peut être créé suivi par lots et périssable', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('products.store'), lotProductPayload(['tracks_lots' => '1', 'is_perishable' => '1']))
        ->assertSessionHasNoErrors();

    $product = Product::firstOrFail();

    expect($product->tracks_lots)->toBeTrue()->and($product->is_perishable)->toBeTrue();
});

test('un produit suivi par lots peut être non périssable', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('products.store'), lotProductPayload(['tracks_lots' => '1', 'is_perishable' => '0']))
        ->assertSessionHasNoErrors();

    $product = Product::firstOrFail();

    expect($product->tracks_lots)->toBeTrue()->and($product->is_perishable)->toBeFalse();
});

test('un produit non suivi par lots ne peut pas être périssable', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('products.store'), lotProductPayload(['tracks_lots' => '0', 'is_perishable' => '1']))
        ->assertSessionHasNoErrors();

    $product = Product::firstOrFail();

    expect($product->tracks_lots)->toBeFalse()->and($product->is_perishable)->toBeFalse();
});

test("le suivi par lots peut encore être modifié tant que le produit n'a aucun mouvement", function () {
    $product = Product::factory()->create(['sku' => 'VAC-100']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('products.update', $product), lotProductPayload([
            'sku' => 'VAC-100',
            'category_id' => $product->category_id,
            'tracks_lots' => '1',
            'is_perishable' => '1',
        ]))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->tracks_lots)->toBeTrue();
});

test('les réglages de lots sont verrouillés dès le premier mouvement', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->tracked()->create();

    app(StockMovementService::class)->recordEntry(
        $product, 5, $admin, lotNumber: 'A', expiresAt: now()->addDays(30),
    );

    $this->actingAs($admin)
        ->put(route('products.update', $product), lotProductPayload([
            'sku' => $product->sku,
            'category_id' => $product->category_id,
            'tracks_lots' => '0',
            'is_perishable' => '0',
        ]))
        ->assertSessionHasNoErrors();

    $fresh = $product->fresh();
    expect($fresh->tracks_lots)->toBeTrue()->and($fresh->is_perishable)->toBeTrue();

    $this->actingAs($admin)->get(route('products.edit', $product))
        ->assertOk()
        ->assertSee('verrouillé');
});

test('la fiche produit liste les lots en stock avec leur état de péremption', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked(false)->create();
    $service = app(StockMovementService::class);

    $service->recordEntry($product, 5, $user, lotNumber: 'LOT-EXPIRE', expiresAt: now()->addDays(10));
    $service->recordEntry($product, 6, $user, lotNumber: 'LOT-BIENTOT', expiresAt: now()->addDays(5));
    $service->recordEntry($product, 7, $user, lotNumber: 'LOT-SANS-DATE');
    $service->recordEntry($product, 8, $user, lotNumber: 'LOT-VIDE', expiresAt: now()->addDays(40));

    StockLot::where('lot_number', 'LOT-EXPIRE')->update(['expires_at' => today()->subDays(2)->toDateString()]);
    $service->recordAdjustment($product, 0, $user, 'Lot vide', lotId: StockLot::where('lot_number', 'LOT-VIDE')->value('id'));

    $this->actingAs($user)->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('LOT-EXPIRE')->assertSee('Expiré depuis 2 j')
        ->assertSee('LOT-BIENTOT')->assertSee('Expire dans 5 j')
        ->assertSee('LOT-SANS-DATE')->assertSee('Sans date de péremption')
        ->assertDontSee('LOT-VIDE');
});

test("la fiche d'un produit sans lots n'affiche pas de section de lots", function () {
    $this->actingAs(User::factory()->create())
        ->get(route('products.show', Product::factory()->create()))
        ->assertOk()
        ->assertDontSee('Lots en stock');
});

test("le libellé d'expiration d'un lot est lisible", function () {
    $lot = new StockLot;
    expect($lot->expiryLabel())->toBe('Sans date de péremption');

    $lot->expires_at = today()->addDays(5);
    expect($lot->expiryLabel())->toBe('Expire dans 5 j');

    $lot->expires_at = today();
    expect($lot->expiryLabel())->toBe("Expire aujourd'hui");

    $lot->expires_at = today()->subDays(3);
    expect($lot->expiryLabel())->toBe('Expiré depuis 3 j');
});