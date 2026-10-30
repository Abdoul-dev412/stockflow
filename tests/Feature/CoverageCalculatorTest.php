<?php

use App\Enums\CoverageLevel;
use App\Enums\MovementReason;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Services\CoverageCalculator;
use App\Services\StockMovementService;
use App\Support\Coverage;
use Illuminate\Support\Facades\DB;

/**
 * Produit dont l'historique commence il y a 40 jours, avec $consumed unités vendues
 * il y a 5 jours (donc $consumed / 30 par jour) et $quantity unités en stock.
 */
function coverageProduct(int $quantity, int $consumed = 60, array $attributes = []): Product
{
    $product = Product::factory()->create(array_merge(['quantity' => 0], $attributes));
    $user = User::factory()->create();
    $service = app(StockMovementService::class);

    $service->recordEntry($product, $quantity + $consumed, $user, occurredAt: now()->subDays(40));

    if ($consumed > 0) {
        $service->recordExit($product, $consumed, $user, MovementReason::Sale, occurredAt: now()->subDays(5));
    }

    return $product->fresh();
}

function coverageOf(Product $product): Coverage
{
    return app(CoverageCalculator::class)->forProduct($product);
}

test('la consommation ne compte que les ventes et la consommation interne', function () {
    $user = User::factory()->create();
    $service = app(StockMovementService::class);
    $product = Product::factory()->create(['quantity' => 0]);

    $service->recordEntry($product, 1000, $user, occurredAt: now()->subDays(40));
    $service->recordExit($product, 30, $user, MovementReason::Sale, occurredAt: now()->subDays(5));
    $service->recordExit($product, 30, $user, MovementReason::InternalUse, occurredAt: now()->subDays(10));

    foreach ([[MovementReason::Loss, 50], [MovementReason::Breakage, 20], [MovementReason::Theft, 10], [MovementReason::Expiry, 5]] as [$reason, $quantity]) {
        $service->recordExit($product, $quantity, $user, $reason, occurredAt: now()->subDays(3));
    }

    $service->recordAdjustment($product, 800, $user, 'Inventaire', occurredAt: now()->subDays(2));

    $coverage = coverageOf($product->fresh());

    expect($coverage->usableQuantity)->toBe(800)
        ->and($coverage->dailyConsumption)->toEqualWithDelta(2.0, 0.0001)   // 60 unités / 30 jours
        ->and($coverage->days)->toEqualWithDelta(400.0, 0.001)
        ->and($coverage->level)->toBe(CoverageLevel::Comfortable);
});

test('les mouvements hors de la fenêtre de calcul sont ignorés', function () {
    $user = User::factory()->create();
    $service = app(StockMovementService::class);
    $product = Product::factory()->create(['quantity' => 0]);

    $service->recordEntry($product, 500, $user, occurredAt: now()->subDays(50));
    $service->recordExit($product, 100, $user, MovementReason::Sale, occurredAt: now()->subDays(45));
    $service->recordExit($product, 30, $user, MovementReason::Sale, occurredAt: now()->subDays(5));

    $coverage = coverageOf($product->fresh());

    expect($coverage->dailyConsumption)->toEqualWithDelta(1.0, 0.0001)
        ->and($coverage->days)->toEqualWithDelta(370.0, 0.001);
});

test("sur un historique plus court que la fenêtre, on moyenne sur l'historique", function () {
    $user = User::factory()->create();
    $service = app(StockMovementService::class);
    $product = Product::factory()->create(['quantity' => 0]);

    $service->recordEntry($product, 100, $user, occurredAt: now()->subDays(10)->subHour());
    $service->recordExit($product, 20, $user, MovementReason::Sale, occurredAt: now()->subDays(5));

    $coverage = coverageOf($product->fresh());

    expect($coverage->historyDays)->toBe(10)
        ->and($coverage->dailyConsumption)->toEqualWithDelta(2.0, 0.0001)  // 20 / 10 jours, et non 20 / 30
        ->and($coverage->days)->toEqualWithDelta(40.0, 0.001);
});

test('moins de sept jours d\'historique : couverture indéterminée', function () {
    $user = User::factory()->create();
    $service = app(StockMovementService::class);

    $short = Product::factory()->create(['quantity' => 0]);
    $service->recordEntry($short, 100, $user, occurredAt: now()->subDays(6)->subHours(23));
    $service->recordExit($short, 14, $user, MovementReason::Sale, occurredAt: now()->subDay());

    $coverage = coverageOf($short->fresh());

    expect($coverage->level)->toBe(CoverageLevel::Undetermined)
        ->and($coverage->note)->toContain('Historique insuffisant')
        ->and($coverage->days)->toBeNull()
        ->and($coverage->dailyConsumption)->toBeNull()
        ->and($coverage->stockoutDate)->toBeNull();

    // Sept jours révolus : l'estimation devient possible.
    $enough = Product::factory()->create(['quantity' => 0]);
    $service->recordEntry($enough, 100, $user, occurredAt: now()->subDays(7)->subHour());
    $service->recordExit($enough, 14, $user, MovementReason::Sale, occurredAt: now()->subDay());

    expect(coverageOf($enough->fresh())->dailyConsumption)->toEqualWithDelta(2.0, 0.0001); // 14 / 7 jours
});

test('sans consommation ou sans aucun mouvement, la couverture est indéterminée', function () {
    $noSales = coverageProduct(50, consumed: 0);
    $noMovement = Product::factory()->create(['quantity' => 4]);

    expect(coverageOf($noSales)->level)->toBe(CoverageLevel::Undetermined)
        ->and(coverageOf($noSales)->note)->toContain('Aucune vente');

    expect(coverageOf($noMovement)->level)->toBe(CoverageLevel::Undetermined)
        ->and(coverageOf($noMovement)->note)->toContain('Historique insuffisant');
});

test('les niveaux suivent les seuils : moins de 7 jours critique, moins de 15 attention', function () {
    // Consommation de 2 par jour : 13 en stock = 6,5 jours, 14 = 7, 29 = 14,5, 30 = 15.
    $expected = [
        0 => CoverageLevel::Critical,
        13 => CoverageLevel::Critical,
        14 => CoverageLevel::Attention,
        29 => CoverageLevel::Attention,
        30 => CoverageLevel::Comfortable,
    ];

    foreach ($expected as $quantity => $level) {
        expect(coverageOf(coverageProduct($quantity))->level)->toBe($level, "stock de {$quantity}");
    }
});

test('la date de rupture estimée est le jour où le stock s\'épuise', function () {
    $this->travelTo(now()->setTime(10, 0));

    $coverage = coverageOf(coverageProduct(13));  // 6,5 jours

    expect($coverage->days)->toEqualWithDelta(6.5, 0.001)
        ->and($coverage->daysLabel())->toBe('6 j')
        ->and($coverage->stockoutDate->toDateString())->toBe(today()->addDays(6)->toDateString());

    $empty = coverageOf(coverageProduct(0));

    expect($empty->days)->toEqualWithDelta(0.0, 0.001)
        ->and($empty->daysLabel())->toBe('< 1 j')
        ->and($empty->stockoutDate->toDateString())->toBe(today()->toDateString())
        ->and($empty->level)->toBe(CoverageLevel::Critical);
});

test('une vente annulée par une correction ne compte plus comme consommation', function () {
    $user = User::factory()->create();
    $service = app(StockMovementService::class);
    $product = Product::factory()->create(['quantity' => 0]);

    $service->recordEntry($product, 200, $user, occurredAt: now()->subDays(40));
    $saleA = $service->recordExit($product, 30, $user, MovementReason::Sale, occurredAt: now()->subDays(5));
    $service->recordExit($product, 30, $user, MovementReason::Sale, occurredAt: now()->subDays(4));
    $service->recordCorrection($saleA, $user, 'Vente annulée');

    $coverage = coverageOf($product->fresh());

    expect($coverage->dailyConsumption)->toEqualWithDelta(1.0, 0.0001)  // 30 / 30 jours
        ->and($coverage->usableQuantity)->toBe(170);
});

test('les lots expirés sont exclus du stock utilisable', function () {
    $user = User::factory()->create();
    $service = app(StockMovementService::class);
    $product = Product::factory()->tracked(false)->create();

    $service->recordEntry($product, 40, $user, occurredAt: now()->subDays(40), lotNumber: 'EXP', expiresAt: now()->addDays(10));
    $service->recordEntry($product, 20, $user, occurredAt: now()->subDays(40), lotNumber: 'OK', expiresAt: now()->addDays(100));
    $service->recordExit($product, 6, $user, MovementReason::Sale, occurredAt: now()->subDays(5)); // pris dans EXP (FEFO)

    StockLot::where('lot_number', 'EXP')->update(['expires_at' => today()->subDay()->toDateString()]);

    $coverage = coverageOf($product->fresh());

    expect($product->fresh()->quantity)->toBe(54)
        ->and($coverage->usableQuantity)->toBe(20)       // 54 - 34 unités expirées
        ->and($coverage->dailyConsumption)->toEqualWithDelta(0.2, 0.0001)
        ->and($coverage->days)->toEqualWithDelta(100.0, 0.001);
});

test('la fenêtre de calcul est configurable', function () {
    $user = User::factory()->create();
    $service = app(StockMovementService::class);
    $product = Product::factory()->create(['quantity' => 0]);

    $service->recordEntry($product, 500, $user, occurredAt: now()->subDays(50));
    $service->recordExit($product, 30, $user, MovementReason::Sale, occurredAt: now()->subDays(20));
    $service->recordExit($product, 20, $user, MovementReason::Sale, occurredAt: now()->subDays(5));
    $product = $product->fresh();

    expect(coverageOf($product)->dailyConsumption)->toEqualWithDelta(50 / 30, 0.0001);

    config(['stockflow.coverage.window_days' => 10]);

    expect(coverageOf($product)->dailyConsumption)->toEqualWithDelta(2.0, 0.0001);   // 20 / 10 jours
});

test('les seuils de couverture sont configurables', function () {
    // 13 en stock à 2 par jour = 6,5 jours : critique par défaut, confortable avec des seuils à 3 et 5 jours.
    $candidate = coverageProduct(13);
    expect(coverageOf($candidate)->level)->toBe(CoverageLevel::Critical);

    config(['stockflow.coverage.critical_days' => 3, 'stockflow.coverage.attention_days' => 5]);
    expect(coverageOf($candidate)->level)->toBe(CoverageLevel::Comfortable);
});

test('le calcul de plusieurs produits est cohérent avec le calcul unitaire, en deux requêtes', function () {
    $products = collect([0, 5, 13, 14, 29, 30, 200])->map(fn (int $quantity) => coverageProduct($quantity));
    $calculator = app(CoverageCalculator::class);

    DB::enableQueryLog();
    $bulk = $calculator->forProducts($products);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(2);

    foreach ($products as $product) {
        $single = $calculator->forProduct($product);

        expect($bulk->get($product->id)->level)->toBe($single->level)
            ->and($bulk->get($product->id)->days)->toEqual($single->days);
    }
});

test('le calcul sur tous les produits actifs ignore les produits inactifs', function () {
    coverageProduct(10);
    coverageProduct(10)->update(['is_active' => false]);

    $rows = app(CoverageCalculator::class)->forActiveProducts();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['product']->is_active)->toBeTrue();
});