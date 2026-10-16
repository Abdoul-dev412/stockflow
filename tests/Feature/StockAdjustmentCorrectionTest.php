<?php

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function stockService(): StockMovementService
{
    return app(StockMovementService::class);
}

// ---------------------------------------------------------------- Ajustements

test("un ajustement à la baisse enregistre l'écart constaté lors d'un inventaire", function () {
    $product = Product::factory()->create(['quantity' => 40]);
    $user = User::factory()->create();

    $movement = stockService()->recordAdjustment($product, 37, $user, 'Inventaire du magasin : 3 sacs manquants');

    expect($product->fresh()->quantity)->toBe(37)
        ->and($product->quantity)->toBe(37);

    expect($movement->type)->toBe(MovementType::Adjustment)
        ->and($movement->reason_code)->toBe(MovementReason::Inventory)
        ->and($movement->quantity)->toBe(3)
        ->and($movement->quantity_before)->toBe(40)
        ->and($movement->quantity_after)->toBe(37)
        ->and($movement->signedQuantity())->toBe(-3)
        ->and($movement->reason)->toBe('Inventaire du magasin : 3 sacs manquants')
        ->and($movement->status)->toBe(MovementStatus::Applied);
});

test('un ajustement à la hausse fonctionne aussi', function () {
    $product = Product::factory()->create(['quantity' => 10]);

    $movement = stockService()->recordAdjustment($product, 14, User::factory()->create(), 'Cartons retrouvés');

    expect($product->fresh()->quantity)->toBe(14);
    expect($movement->quantity)->toBe(4)
        ->and($movement->signedQuantity())->toBe(4);
});

test('un ajustement sans écart est refusé', function () {
    $product = Product::factory()->create(['quantity' => 10]);

    expect(fn () => stockService()->recordAdjustment($product, 10, User::factory()->create(), 'Rien à signaler'))
        ->toThrow(StockMovementException::class);

    expect(StockMovement::count())->toBe(0);
});

test('une quantité constatée négative est refusée', function () {
    $product = Product::factory()->create(['quantity' => 10]);

    expect(fn () => stockService()->recordAdjustment($product, -1, User::factory()->create(), 'Erreur'))
        ->toThrow(StockMovementException::class);

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::count())->toBe(0);
});

test('un inventaire peut constater un stock à zéro', function () {
    $product = Product::factory()->create(['quantity' => 6]);

    stockService()->recordAdjustment($product, 0, User::factory()->create(), 'Étagère vide à l\'inventaire');

    expect($product->fresh()->quantity)->toBe(0);
});

test('un ajustement doit être justifié', function () {
    $product = Product::factory()->create(['quantity' => 10]);
    $user = User::factory()->create();

    foreach ([null, '', '   '] as $blank) {
        expect(fn () => stockService()->recordAdjustment($product, 8, $user, $blank))
            ->toThrow(StockMovementException::class);
    }

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::count())->toBe(0);
});

test('un produit inactif ne peut pas être ajusté', function () {
    $product = Product::factory()->inactive()->create(['quantity' => 10]);

    expect(fn () => stockService()->recordAdjustment($product, 8, User::factory()->create(), 'Inventaire'))
        ->toThrow(StockMovementException::class);

    expect($product->fresh()->quantity)->toBe(10);
});

// ---------------------------------------------------------------- Corrections

test("la correction d'une entrée retire la quantité ajoutée, sans toucher à l'original", function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);
    $entry = stockService()->recordEntry($product, 5, $user);

    $correction = stockService()->recordCorrection($entry, $user, 'Livraison saisie deux fois');

    expect($product->fresh()->quantity)->toBe(10);

    expect($correction->type)->toBe(MovementType::Correction)
        ->and($correction->reason_code)->toBe(MovementReason::Correction)
        ->and($correction->corrects_movement_id)->toBe($entry->id)
        ->and($correction->quantity)->toBe(5)
        ->and($correction->quantity_before)->toBe(15)
        ->and($correction->quantity_after)->toBe(10)
        ->and($correction->signedQuantity())->toBe(-5)
        ->and($correction->reason)->toBe('Livraison saisie deux fois')
        ->and($correction->status)->toBe(MovementStatus::Applied);

    $entry = $entry->fresh();
    expect($entry->quantity)->toBe(5)
        ->and($entry->quantity_after)->toBe(15)
        ->and($entry->status)->toBe(MovementStatus::Applied);
});

test("la correction d'une sortie rend la quantité retirée", function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 20]);
    $exit = stockService()->recordExit($product, 8, $user, MovementReason::Sale);

    $correction = stockService()->recordCorrection($exit, $user, 'Vente annulée par le client');

    expect($product->fresh()->quantity)->toBe(20);
    expect($correction->signedQuantity())->toBe(8);
});

test("la correction d'un ajustement rétablit l'ancien stock, dans les deux sens", function () {
    $user = User::factory()->create();

    $down = Product::factory()->create(['quantity' => 10]);
    $adjustDown = stockService()->recordAdjustment($down, 7, $user, 'Comptage erroné');
    stockService()->recordCorrection($adjustDown, $user, 'Comptage refait : le stock était juste');
    expect($down->fresh()->quantity)->toBe(10);

    $up = Product::factory()->create(['quantity' => 10]);
    $adjustUp = stockService()->recordAdjustment($up, 14, $user, 'Comptage erroné');
    stockService()->recordCorrection($adjustUp, $user, 'Comptage refait : le stock était juste');
    expect($up->fresh()->quantity)->toBe(10);
});

test('un mouvement ne peut être corrigé qu\'une seule fois', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);
    $entry = stockService()->recordEntry($product, 5, $user);

    stockService()->recordCorrection($entry, $user, 'Première correction');

    expect(fn () => stockService()->recordCorrection($entry, $user, 'Deuxième correction'))
        ->toThrow(StockMovementException::class, 'déjà été corrigé');

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::where('corrects_movement_id', $entry->id)->count())->toBe(1);
});

test('une correction ne peut pas être corrigée', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);
    $entry = stockService()->recordEntry($product, 5, $user);
    $correction = stockService()->recordCorrection($entry, $user, 'Erreur de saisie');

    expect(fn () => stockService()->recordCorrection($correction, $user, 'Je me suis trompé'))
        ->toThrow(StockMovementException::class);

    expect($product->fresh()->quantity)->toBe(10);
});

test('une correction doit être justifiée', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);
    $entry = stockService()->recordEntry($product, 5, $user);

    foreach ([null, '', '   '] as $blank) {
        expect(fn () => stockService()->recordCorrection($entry, $user, $blank))
            ->toThrow(StockMovementException::class);
    }

    expect($product->fresh()->quantity)->toBe(15);
    expect(StockMovement::where('type', MovementType::Correction)->count())->toBe(0);
});

test('une correction qui rendrait le stock négatif est refusée', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 0]);
    $entry = stockService()->recordEntry($product, 10, $user);
    stockService()->recordExit($product, 8, $user, MovementReason::Sale);

    // Annuler l'entrée de 10 laisserait 2 - 10 = -8.
    expect(fn () => stockService()->recordCorrection($entry, $user, 'Entrée erronée'))
        ->toThrow(InsufficientStockException::class);

    expect($product->fresh()->quantity)->toBe(2);
    expect(StockMovement::where('type', MovementType::Correction)->count())->toBe(0);
});

test('une correction reste possible sur un produit devenu inactif', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 0]);
    $entry = stockService()->recordEntry($product, 5, $user);
    $product->update(['is_active' => false]);

    stockService()->recordCorrection($entry, $user, 'Produit retiré, entrée saisie par erreur');

    expect($product->fresh()->quantity)->toBe(0);
});

test('un utilisateur désactivé ne peut pas enregistrer de correction', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 0]);
    $entry = stockService()->recordEntry($product, 5, $user);

    expect(fn () => stockService()->recordCorrection($entry, User::factory()->inactive()->create(), 'Tentative'))
        ->toThrow(StockMovementException::class);

    expect($product->fresh()->quantity)->toBe(5);
});

test("seul un mouvement appliqué peut être corrigé", function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);

    $conflict = StockMovement::create([
        'uuid' => (string) Str::uuid(),
        'product_id' => $product->id,
        'user_id' => $user->id,
        'type' => MovementType::Exit,
        'reason_code' => MovementReason::Sale,
        'quantity' => 4,
        'status' => MovementStatus::Conflict,
        'occurred_at' => now(),
        'recorded_at' => now(),
    ]);

    expect(fn () => stockService()->recordCorrection($conflict, $user, 'Tentative'))
        ->toThrow(StockMovementException::class);

    expect($product->fresh()->quantity)->toBe(10);
});

test('la base refuse deux corrections du même mouvement', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);
    $entry = stockService()->recordEntry($product, 5, $user);
    stockService()->recordCorrection($entry, $user, 'Première correction');

    expect(fn () => DB::table('stock_movements')->insert([
        'uuid' => (string) Str::uuid(),
        'product_id' => $product->id,
        'user_id' => $user->id,
        'type' => 'correction',
        'reason_code' => 'correction',
        'quantity' => 5,
        'corrects_movement_id' => $entry->id,
        'status' => 'applied',
        'occurred_at' => now(),
        'recorded_at' => now(),
    ]))->toThrow(QueryException::class);
});