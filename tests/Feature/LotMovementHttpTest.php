<?php

use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;

function lotHttpService(): StockMovementService
{
    return app(StockMovementService::class);
}

test("le formulaire d'entrée demande un numéro de lot seulement pour un produit suivi par lots", function () {
    $manager = User::factory()->create();

    $this->actingAs($manager)->get(route('movements.create', [Product::factory()->tracked()->create(), 'entry']))
        ->assertOk()->assertSee('Numéro de lot')->assertSee('Date de péremption');

    $this->actingAs($manager)->get(route('movements.create', [Product::factory()->create(), 'entry']))
        ->assertOk()->assertDontSee('Numéro de lot');
});

test('une entrée avec un lot crée le lot et met à jour le stock', function () {
    $manager = User::factory()->create();
    $product = Product::factory()->tracked()->create();
    $expiry = now()->addDays(30)->format('Y-m-d');

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'entry']), [
            'quantity' => 12,
            'reason_code' => 'reception',
            'lot_number' => ' ndv-2610 ',
            'expires_at' => $expiry,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('products.show', $product));

    $lot = StockLot::firstOrFail();

    expect($lot->lot_number)->toBe('NDV-2610')
        ->and($lot->quantity_remaining)->toBe(12)
        ->and($lot->expires_at->toDateString())->toBe($expiry)
        ->and($product->fresh()->quantity)->toBe(12);
});

test('le numéro de lot et, pour un produit périssable, la date de péremption sont obligatoires', function () {
    $manager = User::factory()->create();
    $perishable = Product::factory()->tracked(true)->create();
    $durable = Product::factory()->tracked(false)->create();
    $future = now()->addDays(5)->format('Y-m-d');

    $this->actingAs($manager)
        ->post(route('movements.store', [$perishable, 'entry']), [
            'quantity' => 5, 'reason_code' => 'reception', 'expires_at' => $future,
        ])
        ->assertSessionHasErrors('lot_number');

    $this->actingAs($manager)
        ->post(route('movements.store', [$perishable, 'entry']), [
            'quantity' => 5, 'reason_code' => 'reception', 'lot_number' => 'A',
        ])
        ->assertSessionHasErrors('expires_at');

    $this->actingAs($manager)
        ->post(route('movements.store', [$durable, 'entry']), [
            'quantity' => 5, 'reason_code' => 'reception', 'lot_number' => 'A',
        ])
        ->assertSessionHasNoErrors();

    expect(StockLot::count())->toBe(1);
});

test('une date de péremption dépassée ou invalide est refusée', function () {
    $manager = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    foreach ([now()->subDay()->format('Y-m-d'), 'pas-une-date', '31/12/2030'] as $invalid) {
        $this->actingAs($manager)
            ->post(route('movements.store', [$product, 'entry']), [
                'quantity' => 5, 'reason_code' => 'reception', 'lot_number' => 'A', 'expires_at' => $invalid,
            ])
            ->assertSessionHasErrors('expires_at');
    }

    expect(StockLot::count())->toBe(0)->and(StockMovement::count())->toBe(0);
});

test('une sortie sur un produit à lots suit la règle FEFO', function () {
    $manager = User::factory()->create();
    $product = Product::factory()->tracked(false)->create();

    lotHttpService()->recordEntry($product, 5, $manager, lotNumber: 'A', expiresAt: now()->addDays(10));
    lotHttpService()->recordEntry($product, 5, $manager, lotNumber: 'B', expiresAt: now()->addDays(5));

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'exit']), ['quantity' => 7, 'reason_code' => 'sale'])
        ->assertSessionHasNoErrors();

    expect(StockLot::orderBy('lot_number')->pluck('quantity_remaining', 'lot_number')->all())
        ->toBe(['A' => 3, 'B' => 0]);
});

test('une sortie peut cibler un lot précis', function () {
    $manager = User::factory()->create();
    $product = Product::factory()->tracked(false)->create();

    lotHttpService()->recordEntry($product, 5, $manager, lotNumber: 'A', expiresAt: now()->addDays(10));
    lotHttpService()->recordEntry($product, 5, $manager, lotNumber: 'B', expiresAt: now()->addDays(5));

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'exit']), [
            'quantity' => 2, 'reason_code' => 'sale', 'lot_id' => StockLot::where('lot_number', 'A')->value('id'),
        ])
        ->assertSessionHasNoErrors();

    expect(StockLot::orderBy('lot_number')->pluck('quantity_remaining', 'lot_number')->all())
        ->toBe(['A' => 3, 'B' => 5]);
});

test('une sortie supérieure au stock utilisable explique que des lots sont expirés', function () {
    $manager = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotHttpService()->recordEntry($product, 10, $manager, lotNumber: 'EXP', expiresAt: now()->addDays(5));
    lotHttpService()->recordEntry($product, 4, $manager, lotNumber: 'OK', expiresAt: now()->addDays(100));
    StockLot::where('lot_number', 'EXP')->update(['expires_at' => today()->subDay()->toDateString()]);

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'exit']), ['quantity' => 6, 'reason_code' => 'sale'])
        ->assertSessionHasErrors('quantity');

    expect(session('errors')->get('quantity')[0])->toContain('lots expirés');
    expect($product->fresh()->quantity)->toBe(14);
});

test("l'inventaire d'un produit à lots exige le lot compté", function () {
    $manager = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotHttpService()->recordEntry($product, 10, $manager, lotNumber: 'A', expiresAt: now()->addDays(10));

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'adjustment']), [
            'counted_quantity' => 7, 'reason' => 'Inventaire',
        ])
        ->assertSessionHasErrors('lot_id');

    $this->actingAs($manager)
        ->post(route('movements.store', [$product, 'adjustment']), [
            'counted_quantity' => 7, 'reason' => 'Inventaire', 'lot_id' => StockLot::value('id'),
        ])
        ->assertSessionHasNoErrors();

    expect(StockLot::first()->quantity_remaining)->toBe(7)->and($product->fresh()->quantity)->toBe(7);
});

test('un produit sans lots ignore les champs de lot envoyés', function () {
    $product = Product::factory()->create(['quantity' => 10]);

    $this->actingAs(User::factory()->create())
        ->post(route('movements.store', [$product, 'entry']), [
            'quantity' => 5,
            'reason_code' => 'reception',
            'lot_number' => 'X',
            'expires_at' => now()->addDays(5)->format('Y-m-d'),
            'lot_id' => 99,
        ])
        ->assertSessionHasNoErrors();

    expect($product->fresh()->quantity)->toBe(15)->and(StockLot::count())->toBe(0);
});

test("les formulaires proposent les bons lots, dans l'ordre FEFO", function () {
    $manager = User::factory()->create();
    $product = Product::factory()->tracked(false)->create();

    lotHttpService()->recordEntry($product, 5, $manager, lotNumber: 'TARD', expiresAt: now()->addDays(50));
    lotHttpService()->recordEntry($product, 5, $manager, lotNumber: 'TOT', expiresAt: now()->addDays(5));
    lotHttpService()->recordEntry($product, 1, $manager, lotNumber: 'VIDE', expiresAt: now()->addDays(20));
    lotHttpService()->recordAdjustment($product, 0, $manager, 'Lot vide', lotId: StockLot::where('lot_number', 'VIDE')->value('id'));

    $numbers = fn ($lots) => collect($lots)->pluck('number')->all();

    $this->actingAs($manager)->get(route('movements.create', [$product, 'exit']))
        ->assertViewHas('lots', fn ($lots) => $numbers($lots) === ['TOT', 'TARD']);

    $this->actingAs($manager)->get(route('movements.create', [$product, 'adjustment']))
        ->assertViewHas('lots', fn ($lots) => $numbers($lots) === ['TOT', 'VIDE', 'TARD']);

    $this->actingAs($manager)->get(route('movements.create', [$product, 'entry']))
        ->assertViewHas('lots', fn ($lots) => $numbers($lots) === ['TOT', 'VIDE', 'TARD']);
});

test("l'historique affiche les lots touchés par un mouvement", function () {
    $manager = User::factory()->create();
    $product = Product::factory()->tracked(false)->create();

    lotHttpService()->recordEntry($product, 5, $manager, lotNumber: 'LOT-A', expiresAt: now()->addDays(10));
    lotHttpService()->recordEntry($product, 5, $manager, lotNumber: 'LOT-B', expiresAt: now()->addDays(5));
    lotHttpService()->recordExit($product, 8, $manager, App\Enums\MovementReason::Sale);

    $this->actingAs($manager)->get(route('movements.index'))
        ->assertOk()
        ->assertSee('Lots :')
        ->assertSee('LOT-A (-3)')
        ->assertSee('LOT-B (-5)');
});

test("l'export de l'historique indique les lots touchés", function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->tracked(false)->create();

    lotHttpService()->recordEntry($product, 5, $admin, lotNumber: 'LOT-A', expiresAt: now()->addDays(10));
    lotHttpService()->recordExit($product, 3, $admin, App\Enums\MovementReason::Sale);

    $content = $this->actingAs($admin)->get(route('exports.movements'))->streamedContent();
    $lines = array_values(array_filter(preg_split('/\R/', preg_replace('/^\xEF\xBB\xBF/', '', $content))));
    $rows = array_map(fn ($line) => str_getcsv($line, ';', '"', ''), $lines);

    expect(end($rows[0]))->toBe('Lots')
        ->and(end($rows[1]))->toBe('LOT-A:-3')
        ->and(end($rows[2]))->toBe('LOT-A:5');
});