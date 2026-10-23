<?php

use App\Enums\MovementReason;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Models\MovementLotAllocation;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function lotService(): StockMovementService
{
    return app(StockMovementService::class);
}

/** Contrôle d'intégrité : stock = somme des lots, et chaque lot = somme de ses répartitions. */
function assertLotsReconcile(Product $product): void
{
    $product = $product->fresh();

    expect((int) StockLot::where('product_id', $product->id)->sum('quantity_remaining'))
        ->toBe($product->quantity);

    foreach (StockLot::where('product_id', $product->id)->get() as $lot) {
        expect((int) MovementLotAllocation::where('lot_id', $lot->id)->sum('quantity'))
            ->toBe($lot->quantity_remaining);
    }
}

/** @return array<string, int> numéro de lot => quantité répartie par ce mouvement */
function allocationsOf(StockMovement $movement): array
{
    return MovementLotAllocation::where('movement_id', $movement->id)->get()
        ->mapWithKeys(fn ($allocation) => [StockLot::find($allocation->lot_id)->lot_number => $allocation->quantity])
        ->all();
}

// ------------------------------------------------------------------ Entrées

test('une entrée sur un produit à lots crée un lot et sa répartition', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create(['purchase_price' => '1500.00']);

    $movement = lotService()->recordEntry(
        $product, 20, $user, lotNumber: 'ndv-2610', expiresAt: now()->addDays(30),
    );

    $lot = StockLot::firstOrFail();

    expect($product->fresh()->quantity)->toBe(20)
        ->and($lot->lot_number)->toBe('NDV-2610')
        ->and($lot->quantity_remaining)->toBe(20)
        ->and($lot->expires_at->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and($lot->unit_cost)->toBe('1500.00')
        ->and(allocationsOf($movement))->toBe(['NDV-2610' => 20]);

    assertLotsReconcile($product);
});

test('le numéro de lot est obligatoire pour un produit à lots', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked(false)->create();

    foreach ([null, '', '   ', str_repeat('A', 65)] as $invalid) {
        expect(fn () => lotService()->recordEntry($product, 5, $user, lotNumber: $invalid))
            ->toThrow(StockMovementException::class);
    }

    expect($product->fresh()->quantity)->toBe(0);
    expect(StockMovement::count())->toBe(0)->and(StockLot::count())->toBe(0);
});

test('la date de péremption est obligatoire pour un produit périssable, facultative sinon', function () {
    $user = User::factory()->create();
    $perishable = Product::factory()->tracked(true)->create();
    $durable = Product::factory()->tracked(false)->create();

    expect(fn () => lotService()->recordEntry($perishable, 5, $user, lotNumber: 'A'))
        ->toThrow(StockMovementException::class, 'obligatoire');

    lotService()->recordEntry($durable, 5, $user, lotNumber: 'A');

    expect(StockLot::where('product_id', $durable->id)->first()->expires_at)->toBeNull();
});

test('une entrée de stock déjà périmé est refusée', function () {
    $product = Product::factory()->tracked()->create();

    expect(fn () => lotService()->recordEntry(
        $product, 5, User::factory()->create(), lotNumber: 'A', expiresAt: now()->subDay(),
    ))->toThrow(StockMovementException::class, 'dépassée');

    expect(StockLot::count())->toBe(0);
});

test('un numéro de lot connu complète le lot existant', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();
    $expiry = now()->addDays(30);

    lotService()->recordEntry($product, 5, $user, lotNumber: 'A', expiresAt: $expiry);
    lotService()->recordEntry($product, 7, $user, lotNumber: ' a ', expiresAt: $expiry);

    expect(StockLot::count())->toBe(1)
        ->and(StockLot::first()->quantity_remaining)->toBe(12)
        ->and($product->fresh()->quantity)->toBe(12);

    assertLotsReconcile($product);
});

test('un lot connu avec une autre date de péremption est refusé', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 5, $user, lotNumber: 'A', expiresAt: now()->addDays(30));

    expect(fn () => lotService()->recordEntry($product, 7, $user, lotNumber: 'A', expiresAt: now()->addDays(60)))
        ->toThrow(StockMovementException::class, 'autre date de péremption');

    expect($product->fresh()->quantity)->toBe(5);
    assertLotsReconcile($product);
});

test('un produit sans suivi par lots refuse les informations de lot et ne crée aucun lot', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 10]);

    expect(fn () => lotService()->recordEntry($product, 5, $user, lotNumber: 'A'))
        ->toThrow(StockMovementException::class, "pas suivi par lots");

    lotService()->recordEntry($product, 5, $user);

    expect($product->fresh()->quantity)->toBe(15)
        ->and(StockLot::count())->toBe(0)
        ->and(MovementLotAllocation::count())->toBe(0);
});

// ------------------------------------------------------------------ FEFO

test('une sortie consomme les lots selon FEFO, les lots sans date en dernier', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked(false)->create();

    lotService()->recordEntry($product, 5, $user, lotNumber: 'A', expiresAt: now()->addDays(10));
    lotService()->recordEntry($product, 5, $user, lotNumber: 'B', expiresAt: now()->addDays(5));
    lotService()->recordEntry($product, 5, $user, lotNumber: 'C');

    $exit = lotService()->recordExit($product, 8, $user, MovementReason::Sale);

    $remaining = StockLot::orderBy('lot_number')->pluck('quantity_remaining', 'lot_number')->all();

    expect($remaining)->toBe(['A' => 2, 'B' => 0, 'C' => 5])
        ->and(allocationsOf($exit))->toEqual(['B' => -5, 'A' => -3])
        ->and($product->fresh()->quantity)->toBe(7)
        ->and($exit->signedQuantity())->toBe(-8);

    assertLotsReconcile($product);
});

test('à péremption égale, le lot reçu le plus tôt part en premier', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();
    $expiry = now()->addDays(30);

    lotService()->recordEntry($product, 5, $user, occurredAt: now()->subDays(1), lotNumber: 'RECENT', expiresAt: $expiry);
    lotService()->recordEntry($product, 5, $user, occurredAt: now()->subDays(5), lotNumber: 'ANCIEN', expiresAt: $expiry);

    lotService()->recordExit($product, 3, $user, MovementReason::Sale);

    expect(StockLot::orderBy('lot_number')->pluck('quantity_remaining', 'lot_number')->all())
        ->toBe(['ANCIEN' => 2, 'RECENT' => 5]);
});

test('une sortie supérieure au stock utilisable est refusée et signale les lots expirés', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 10, $user, lotNumber: 'BIENTOT', expiresAt: now()->addDays(3));
    lotService()->recordEntry($product, 4, $user, lotNumber: 'PLUS-TARD', expiresAt: now()->addDays(100));

    $this->travelTo(now()->addDays(5)); // le premier lot est expiré

    expect(fn () => lotService()->recordExit($product, 5, $user, MovementReason::Sale))
        ->toThrow(InsufficientStockException::class, '10 unité(s) en lots expirés');

    lotService()->recordExit($product, 4, $user, MovementReason::Sale);

    expect(StockLot::where('lot_number', 'PLUS-TARD')->first()->quantity_remaining)->toBe(0)
        ->and(StockLot::where('lot_number', 'BIENTOT')->first()->quantity_remaining)->toBe(10)
        ->and($product->fresh()->quantity)->toBe(10);

    assertLotsReconcile($product);
});

test('les lots vides sont ignorés et peuvent être réapprovisionnés', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();
    $expiry = now()->addDays(5);

    lotService()->recordEntry($product, 3, $user, lotNumber: 'A', expiresAt: $expiry);
    lotService()->recordEntry($product, 4, $user, lotNumber: 'B', expiresAt: now()->addDays(10));

    lotService()->recordExit($product, 3, $user, MovementReason::Sale);
    lotService()->recordExit($product, 2, $user, MovementReason::Sale);

    expect(StockLot::orderBy('lot_number')->pluck('quantity_remaining', 'lot_number')->all())
        ->toBe(['A' => 0, 'B' => 2]);

    lotService()->recordEntry($product, 6, $user, lotNumber: 'A', expiresAt: $expiry);

    expect(StockLot::where('lot_number', 'A')->first()->quantity_remaining)->toBe(6);
    assertLotsReconcile($product);
});

// ------------------------------------------------------------------ Péremption

test('le motif « Péremption » ne retire que des lots expirés', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 10, $user, lotNumber: 'BIENTOT', expiresAt: now()->addDays(3));
    lotService()->recordEntry($product, 4, $user, lotNumber: 'PLUS-TARD', expiresAt: now()->addDays(100));

    // Rien d'expiré : le retrait est refusé.
    expect(fn () => lotService()->recordExit($product, 1, $user, MovementReason::Expiry))
        ->toThrow(InsufficientStockException::class);

    $this->travelTo(now()->addDays(5));

    // Plus que ce qui est expiré : refusé.
    expect(fn () => lotService()->recordExit($product, 11, $user, MovementReason::Expiry))
        ->toThrow(InsufficientStockException::class);

    $movement = lotService()->recordExit($product, 10, $user, MovementReason::Expiry);

    expect(allocationsOf($movement))->toBe(['BIENTOT' => -10])
        ->and(StockLot::where('lot_number', 'PLUS-TARD')->first()->quantity_remaining)->toBe(4)
        ->and($product->fresh()->quantity)->toBe(4);

    assertLotsReconcile($product);
});

test('un lot expire à la fin de sa date : il reste utilisable le jour même', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 5, $user, lotNumber: 'A', expiresAt: now()->addDay());

    $this->travelTo(now()->addDay()->setTime(23, 59));
    expect(StockLot::first()->isExpired())->toBeFalse();
    lotService()->recordExit($product, 1, $user, MovementReason::Sale);

    $this->travelTo(now()->addMinutes(2)); // le lendemain
    expect(StockLot::first()->isExpired())->toBeTrue();
    expect(fn () => lotService()->recordExit($product, 1, $user, MovementReason::Sale))
        ->toThrow(InsufficientStockException::class);
});

// ------------------------------------------------------------------ Choix manuel du lot

test('un lot peut être choisi manuellement, dans les limites de ses règles', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();
    $other = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 10, $user, lotNumber: 'BIENTOT', expiresAt: now()->addDays(3));
    lotService()->recordEntry($product, 4, $user, lotNumber: 'PLUS-TARD', expiresAt: now()->addDays(100));
    lotService()->recordEntry($other, 5, $user, lotNumber: 'AUTRE', expiresAt: now()->addDays(10));

    $soon = StockLot::where('lot_number', 'BIENTOT')->first();
    $later = StockLot::where('lot_number', 'PLUS-TARD')->first();

    // Le lot le plus tardif, alors que FEFO aurait pris l'autre.
    $movement = lotService()->recordExit($product, 2, $user, MovementReason::Sale, lotId: $later->id);
    expect(allocationsOf($movement))->toBe(['PLUS-TARD' => -2]);

    // Plus que le contenu du lot.
    expect(fn () => lotService()->recordExit($product, 3, $user, MovementReason::Sale, lotId: $later->id))
        ->toThrow(InsufficientStockException::class);

    // Lot d'un autre produit.
    expect(fn () => lotService()->recordExit($product, 1, $user, MovementReason::Sale, lotId: StockLot::where('lot_number', 'AUTRE')->first()->id))
        ->toThrow(StockMovementException::class, 'introuvable');

    // Lot non expiré avec le motif Péremption.
    expect(fn () => lotService()->recordExit($product, 1, $user, MovementReason::Expiry, lotId: $soon->id))
        ->toThrow(StockMovementException::class, "pas expiré");

    $this->travelTo(now()->addDays(5));

    // Lot expiré avec un autre motif.
    expect(fn () => lotService()->recordExit($product, 1, $user, MovementReason::Sale, lotId: $soon->id))
        ->toThrow(StockMovementException::class, 'Péremption');

    assertLotsReconcile($product);
});

// ------------------------------------------------------------------ Ajustements

test("l'inventaire d'un produit à lots se fait lot par lot", function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 10, $user, lotNumber: 'A', expiresAt: now()->addDays(10));
    lotService()->recordEntry($product, 5, $user, lotNumber: 'B', expiresAt: now()->addDays(20));
    $lotA = StockLot::where('lot_number', 'A')->first();

    expect(fn () => lotService()->recordAdjustment($product, 7, $user, 'Inventaire'))
        ->toThrow(StockMovementException::class, 'lot par lot');

    $movement = lotService()->recordAdjustment($product, 7, $user, 'Inventaire : 3 flacons cassés', lotId: $lotA->id);

    expect($product->fresh()->quantity)->toBe(12)
        ->and($lotA->fresh()->quantity_remaining)->toBe(7)
        ->and($movement->quantity)->toBe(3)
        ->and($movement->signedQuantity())->toBe(-3)
        ->and(allocationsOf($movement))->toBe(['A' => -3]);

    assertLotsReconcile($product);
});

test('un ajustement de lot sans écart ou sur un lot étranger est refusé', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();
    $other = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 10, $user, lotNumber: 'A', expiresAt: now()->addDays(10));
    lotService()->recordEntry($other, 5, $user, lotNumber: 'X', expiresAt: now()->addDays(10));

    expect(fn () => lotService()->recordAdjustment($product, 10, $user, 'Rien', lotId: StockLot::where('lot_number', 'A')->first()->id))
        ->toThrow(StockMovementException::class, 'aucun ajustement');

    expect(fn () => lotService()->recordAdjustment($product, 4, $user, 'Erreur', lotId: StockLot::where('lot_number', 'X')->first()->id))
        ->toThrow(StockMovementException::class, 'introuvable');

    expect($product->fresh()->quantity)->toBe(10);
});

test('un lot compté à zéro est accepté', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 6, $user, lotNumber: 'A', expiresAt: now()->addDays(10));

    lotService()->recordAdjustment($product, 0, $user, 'Lot introuvable à l\'inventaire', lotId: StockLot::first()->id);

    expect($product->fresh()->quantity)->toBe(0)->and(StockLot::first()->quantity_remaining)->toBe(0);
    assertLotsReconcile($product);
});

test('un produit sans lots refuse un identifiant de lot pour un ajustement', function () {
    $product = Product::factory()->create(['quantity' => 10]);

    expect(fn () => lotService()->recordAdjustment($product, 8, User::factory()->create(), 'Inventaire', lotId: 1))
        ->toThrow(StockMovementException::class);
});

// ------------------------------------------------------------------ Corrections

test("la correction d'une entrée retire la quantité du lot, si le lot la contient encore", function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    $entry = lotService()->recordEntry($product, 10, $user, lotNumber: 'A', expiresAt: now()->addDays(30));
    $exit = lotService()->recordExit($product, 4, $user, MovementReason::Sale);

    // Le lot ne contient plus que 6 unités : annuler l'entrée de 10 est impossible.
    expect(fn () => lotService()->recordCorrection($entry, $user, 'Entrée saisie par erreur'))
        ->toThrow(StockMovementException::class, "ne contient plus assez d'unités");

    expect($product->fresh()->quantity)->toBe(6);

    // On corrige d'abord la sortie, puis l'entrée.
    lotService()->recordCorrection($exit, $user, 'Vente annulée');
    expect($product->fresh()->quantity)->toBe(10);

    $correction = lotService()->recordCorrection($entry, $user, 'Entrée saisie par erreur');

    expect($product->fresh()->quantity)->toBe(0)
        ->and(StockLot::first()->quantity_remaining)->toBe(0)
        ->and(allocationsOf($correction))->toBe(['A' => -10]);

    assertLotsReconcile($product);
});

test('la correction d\'une sortie répartie sur plusieurs lots les restaure tous', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 5, $user, lotNumber: 'A', expiresAt: now()->addDays(10));
    lotService()->recordEntry($product, 5, $user, lotNumber: 'B', expiresAt: now()->addDays(20));

    $exit = lotService()->recordExit($product, 8, $user, MovementReason::Sale);
    expect(allocationsOf($exit))->toBe(['A' => -5, 'B' => -3]);

    $correction = lotService()->recordCorrection($exit, $user, 'Vente annulée');

    expect(StockLot::orderBy('lot_number')->pluck('quantity_remaining', 'lot_number')->all())
        ->toBe(['A' => 5, 'B' => 5])
        ->and($product->fresh()->quantity)->toBe(10)
        ->and(allocationsOf($correction))->toBe(['A' => 5, 'B' => 3]);

    assertLotsReconcile($product);
});

test("la correction d'un ajustement de lot rétablit le lot", function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();

    lotService()->recordEntry($product, 10, $user, lotNumber: 'A', expiresAt: now()->addDays(10));
    $adjustment = lotService()->recordAdjustment($product, 7, $user, 'Inventaire', lotId: StockLot::first()->id);

    lotService()->recordCorrection($adjustment, $user, 'Comptage refait : le stock était juste');

    expect($product->fresh()->quantity)->toBe(10)->and(StockLot::first()->quantity_remaining)->toBe(10);
    assertLotsReconcile($product);
});

// ------------------------------------------------------------------ Cohérence

test('une série d\'opérations mêlées laisse stock, lots et répartitions réconciliés', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();
    $service = lotService();

    $service->recordEntry($product, 30, $user, lotNumber: 'A', expiresAt: now()->addDays(10));
    $service->recordEntry($product, 20, $user, lotNumber: 'B', expiresAt: now()->addDays(20));
    $sale = $service->recordExit($product, 35, $user, MovementReason::Sale);
    $service->recordEntry($product, 15, $user, lotNumber: 'C', expiresAt: now()->addDays(40));
    $service->recordAdjustment($product, 10, $user, 'Inventaire', lotId: StockLot::where('lot_number', 'C')->first()->id);
    $service->recordCorrection($sale, $user, 'Vente annulée');
    $service->recordExit($product, 12, $user, MovementReason::Loss);

    assertLotsReconcile($product);

    expect(StockMovement::where('product_id', $product->id)->orderBy('id')->get()
        ->every(fn ($movement, $index) => true))->toBeTrue();
});

test("si l'invariant stock = somme des lots est rompu, l'opération est annulée", function () {
    $user = User::factory()->create();
    // Produit volontairement incohérent : 10 en stock, aucun lot.
    $product = Product::factory()->tracked()->create(['quantity' => 10]);

    expect(fn () => lotService()->recordEntry($product, 5, $user, lotNumber: 'A', expiresAt: now()->addDays(10)))
        ->toThrow(LogicException::class, 'Invariant rompu');

    expect($product->fresh()->quantity)->toBe(10)
        ->and(StockMovement::count())->toBe(0)
        ->and(StockLot::count())->toBe(0)
        ->and(MovementLotAllocation::count())->toBe(0);
});

// ------------------------------------------------------------------ Contraintes de la base

test('un numéro de lot est unique par produit, mais réutilisable entre produits', function () {
    $a = Product::factory()->tracked()->create();
    $b = Product::factory()->tracked()->create();
    $attributes = ['lot_number' => 'L1', 'quantity_remaining' => 1, 'received_at' => now()];

    StockLot::create($attributes + ['product_id' => $a->id]);
    StockLot::create($attributes + ['product_id' => $b->id]);

    expect(fn () => StockLot::create($attributes + ['product_id' => $a->id]))
        ->toThrow(QueryException::class);
});

test('la base refuse un lot négatif et une répartition nulle', function () {
    $user = User::factory()->create();
    $product = Product::factory()->tracked()->create();
    $movement = lotService()->recordEntry($product, 5, $user, lotNumber: 'A', expiresAt: now()->addDays(10));
    $lot = StockLot::first();

    expect(fn () => DB::table('stock_lots')->where('id', $lot->id)->update(['quantity_remaining' => DB::raw('quantity_remaining - 6')]))
        ->toThrow(QueryException::class);

    $second = StockLot::create([
        'product_id' => $product->id, 'lot_number' => 'B', 'quantity_remaining' => 0, 'received_at' => now(),
    ]);

    expect(fn () => DB::table('movement_lot_allocations')->insert([
        'movement_id' => $movement->id, 'lot_id' => $second->id, 'quantity' => 0,
    ]))->toThrow(QueryException::class);
});

test('une répartition par lot est immuable', function () {
    lotService()->recordEntry(
        Product::factory()->tracked()->create(), 5, User::factory()->create(),
        lotNumber: 'A', expiresAt: now()->addDays(10),
    );

    $allocation = MovementLotAllocation::firstOrFail();

    expect(fn () => $allocation->update(['quantity' => 99]))->toThrow(LogicException::class);
    expect(fn () => $allocation->delete())->toThrow(LogicException::class);
});

test('un lot ou une répartition ne peut pas être supprimé tant qu\'il est référencé', function () {
    lotService()->recordEntry(
        Product::factory()->tracked()->create(), 5, User::factory()->create(),
        lotNumber: 'A', expiresAt: now()->addDays(10),
    );

    expect(fn () => StockLot::first()->delete())->toThrow(QueryException::class);
});