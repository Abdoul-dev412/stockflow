<?php

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AbcClassifier;
use App\Services\DormancyCalculator;
use App\Services\StockMovementService;
use App\Services\StockValuation;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

// ------------------------------------------------------------------ Outils de test

function analysisMovement(Product $product, MovementType $type, int $quantity, MovementReason $reason, CarbonInterface $at, array $extra = []): StockMovement
{
    $user = User::query()->first() ?? User::factory()->create();

    return StockMovement::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'product_id' => $product->id,
        'user_id' => $user->id,
        'type' => $type,
        'reason_code' => $reason,
        'quantity' => $quantity,
        'quantity_before' => 1000,
        'quantity_after' => $type === MovementType::Entry ? 1000 + $quantity : 1000 - $quantity,
        'status' => MovementStatus::Applied,
        'occurred_at' => $at,
        'recorded_at' => now(),
    ], $extra));
}

function analysisProduct(array $attributes = []): Product
{
    return Product::factory()->create($attributes + ['quantity' => 10, 'purchase_price' => '10.00']);
}

function analysisSale(Product $product, int $quantity, int $daysAgo = 5, MovementReason $reason = MovementReason::Sale): StockMovement
{
    return analysisMovement($product, MovementType::Exit, $quantity, $reason, now()->subDays($daysAgo));
}

/** Un produit par valeur de consommation (prix 10, donc valeur / 10 unités vendues). Produits P1, P2, … */
function abcScenario(array $values): array
{
    return collect($values)->map(function (int $value, int $index) {
        $product = analysisProduct(['sku' => 'P'.($index + 1)]);
        analysisSale($product, intdiv($value, 10));

        return $product;
    })->all();
}

/** @return array<string, Product> */
function dormancyScenario(): array
{
    $category = Category::factory()->create();
    $make = fn (string $sku, array $attributes = []) => analysisProduct($attributes + ['sku' => $sku, 'category_id' => $category->id]);
    $entry = fn (Product $product, int $daysAgo) => analysisMovement($product, MovementType::Entry, 100, MovementReason::Reception, now()->subDays($daysAgo));

    $products = [
        'A-NEVER' => $make('A-NEVER', ['quantity' => 10, 'purchase_price' => '12.50']),
        'B-ACTIVE' => $make('B-ACTIVE'),
        'C-EMPTY' => $make('C-EMPTY', ['quantity' => 0]),
        'D-INACTIVE' => $make('D-INACTIVE', ['is_active' => false]),
        'E-RECENT' => $make('E-RECENT'),
        'F-OLDSALE' => $make('F-OLDSALE', ['quantity' => 4, 'purchase_price' => '2.50']),
        'G-NONE' => $make('G-NONE'),
        'H-DELETED' => $make('H-DELETED'),
    ];

    $entry($products['A-NEVER'], 100);                          // jamais vendu, en stock depuis 100 jours
    $entry($products['B-ACTIVE'], 100);
    analysisSale($products['B-ACTIVE'], 5, 20);                 // vendu il y a 20 jours
    $entry($products['C-EMPTY'], 200);                          // plus de stock
    $entry($products['D-INACTIVE'], 200);                       // inactif
    $entry($products['E-RECENT'], 10);                          // trop récent
    $entry($products['F-OLDSALE'], 200);
    analysisSale($products['F-OLDSALE'], 1, 150);               // dernière vente il y a 150 jours
    $entry($products['H-DELETED'], 200);
    $products['H-DELETED']->delete();                           // supprimé

    return $products;
}

// ------------------------------------------------------------------ Money

test('les montants sont formatés sans passer par un flottant', function () {
    expect(Money::format('1234567.89'))->toBe('1 234 567,89')
        ->and(Money::format('0'))->toBe('0,00')
        ->and(Money::format('12.5'))->toBe('12,50')
        ->and(Money::format('999.00'))->toBe('999,00')
        ->and(Money::format('1000'))->toBe('1 000,00')
        ->and(Money::format('-1234.5'))->toBe('-1 234,50')
        ->and(Money::format(null))->toBe('0,00')
        ->and(Money::format('12345678901234567.89'))->toBe('12 345 678 901 234 567,89');
});

test('les montants se convertissent en centimes sans perte', function () {
    expect(Money::toCents('1234.56'))->toBe(123456)
        ->and(Money::toCents('0.5'))->toBe(50)
        ->and(Money::toCents('12'))->toBe(1200)
        ->and(Money::toCents('-3.07'))->toBe(-307)
        ->and(Money::toCents(null))->toBe(0)
        ->and(Money::fromCents(123456))->toBe('1234.56')
        ->and(Money::fromCents(5))->toBe('0.05')
        ->and(Money::fromCents(-307))->toBe('-3.07');
});

// ------------------------------------------------------------------ Dormance

test("les jours d'inactivité se comptent depuis la dernière vente, sinon depuis le premier mouvement", function () {
    $calculator = app(DormancyCalculator::class);
    $product = analysisProduct();

    expect($calculator->idleDays($product, now()))->toBeNull();

    analysisMovement($product, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(120));
    expect($calculator->idleDays($product, now()))->toBe(120);

    analysisSale($product, 5, 40);
    expect($calculator->idleDays($product, now()))->toBe(40);

    analysisSale($product, 5, 10, MovementReason::InternalUse);
    expect($calculator->idleDays($product, now()))->toBe(10);
});

test("une perte ne remet pas le compteur à zéro, une vente annulée par une correction non plus", function () {
    $calculator = app(DormancyCalculator::class);
    $product = analysisProduct();

    analysisMovement($product, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(120));
    analysisSale($product, 5, 10, MovementReason::Loss);
    expect($calculator->idleDays($product, now()))->toBe(120);

    $sale = analysisSale($product, 5, 30);
    expect($calculator->idleDays($product, now()))->toBe(30);

    analysisMovement($product, MovementType::Correction, 5, MovementReason::Correction, now()->subDays(29), [
        'corrects_movement_id' => $sale->id,
        'quantity_after' => 1005,
    ]);
    expect($calculator->idleDays($product, now()))->toBe(120);
});

test("le mouvement évalué peut être exclu du calcul d'inactivité", function () {
    $calculator = app(DormancyCalculator::class);
    $product = analysisProduct();

    analysisMovement($product, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(120));
    $previous = analysisSale($product, 5, 60);
    $current = analysisSale($product, 5, 0);

    expect($calculator->idleDays($product, now(), $current->id))->toBe(60)
        ->and($calculator->idleDays($product, now(), $previous->id))->toBe(0);
});

test('la liste des produits dormants applique la définition du cahier des charges', function () {
    dormancyScenario();

    $rows = app(DormancyCalculator::class)->dormantProducts();

    expect($rows->pluck('product.sku')->all())->toBe(['F-OLDSALE', 'A-NEVER'])
        ->and($rows->pluck('idle_days')->all())->toBe([150, 100])
        ->and($rows->pluck('capital')->all())->toBe(['10.00', '125.00'])
        ->and($rows->first()['reference_at']->toDateString())->toBe(now()->subDays(150)->toDateString());
});

test('la liste en une requête et le calcul produit par produit donnent le même résultat', function () {
    dormancyScenario();
    $calculator = app(DormancyCalculator::class);

    $expected = Product::query()->active()->where('quantity', '>', 0)->get()
        ->filter(fn (Product $product) => ($days = $calculator->idleDays($product, now())) !== null
            && $days >= $calculator->thresholdDays())
        ->pluck('id')->sort()->values()->all();

    $actual = $calculator->dormantProducts()->pluck('product.id')->sort()->values()->all();

    expect($actual)->toBe($expected)->and($actual)->not->toBeEmpty();
});

test('le seuil de dormance est configurable', function () {
    dormancyScenario();
    $calculator = app(DormancyCalculator::class);

    config(['stockflow.dormancy.days' => 15]);
    expect($calculator->dormantProducts()->pluck('product.sku')->sort()->values()->all())
        ->toBe(['A-NEVER', 'B-ACTIVE', 'F-OLDSALE']);

    config(['stockflow.dormancy.days' => 200]);
    expect($calculator->dormantProducts())->toBeEmpty();
});

// ------------------------------------------------------------------ ABC

test('les produits sont classés A, B et C par valeur de consommation cumulée', function () {
    abcScenario([600, 250, 80, 50, 20]);

    $rows = app(AbcClassifier::class)->classify();

    expect($rows->pluck('product.sku')->all())->toBe(['P1', 'P2', 'P3', 'P4', 'P5'])
        // P2 franchit les 80 % mais reste en A : la part cumulée AVANT lui (60 %) est inférieure au seuil.
        ->and($rows->pluck('class')->all())->toBe(['A', 'A', 'B', 'B', 'C'])
        ->and($rows->pluck('value')->all())->toBe(['600.00', '250.00', '80.00', '50.00', '20.00'])
        ->and($rows->pluck('units')->all())->toBe([60, 25, 8, 5, 2])
        ->and($rows->pluck('share')->all())->toBe([60.0, 25.0, 8.0, 5.0, 2.0])
        ->and($rows->pluck('cumulative')->all())->toBe([60.0, 85.0, 93.0, 98.0, 100.0]);
});

test('au seuil exact, le produit passe dans la classe suivante', function () {
    abcScenario([800, 150, 50]);

    // Après P1 : exactement 80 % cumulés, pas moins. Après P2 : exactement 95 %.
    expect(app(AbcClassifier::class)->classify()->pluck('class')->all())->toBe(['A', 'B', 'C']);
});

test('le meilleur produit est toujours en classe A', function () {
    abcScenario([1000]);

    expect(app(AbcClassifier::class)->classify()->pluck('class')->all())->toBe(['A']);
});

test('les seuils A et B sont configurables', function () {
    abcScenario([600, 250, 80, 50, 20]);

    config(['stockflow.abc.a_percent' => 50, 'stockflow.abc.b_percent' => 70]);

    expect(app(AbcClassifier::class)->classify()->pluck('class')->all())->toBe(['A', 'B', 'C', 'C', 'C']);
});

test('à valeur égale, le classement est stable par identifiant de produit', function () {
    $products = abcScenario([100, 100, 100]);

    expect(app(AbcClassifier::class)->classify()->pluck('product.id')->all())
        ->toBe(collect($products)->pluck('id')->all());
});

test('seule la consommation des produits actifs, dans la fenêtre, est comptée', function () {
    $counted = analysisProduct(['sku' => 'X-VENDU']);
    analysisSale($counted, 10);                                              // 100

    $internal = analysisProduct(['sku' => 'T-INTERNE']);
    analysisSale($internal, 5, 5, MovementReason::InternalUse);              // 50 : la consommation interne compte

    $old = analysisProduct(['sku' => 'Y-ANCIEN']);
    analysisSale($old, 10, 400);                                             // hors fenêtre de 365 jours

    $lossOnly = analysisProduct(['sku' => 'Z-PERTE']);
    analysisSale($lossOnly, 10, 5, MovementReason::Loss);                    // une perte n'est pas de la consommation

    $expiry = analysisProduct(['sku' => 'E-PEREMPTION']);
    analysisSale($expiry, 10, 5, MovementReason::Expiry);

    $inactive = analysisProduct(['sku' => 'W-INACTIF', 'is_active' => false]);
    analysisSale($inactive, 10);

    $free = analysisProduct(['sku' => 'V-GRATUIT', 'purchase_price' => '0.00']);
    analysisSale($free, 10);                                                 // valeur nulle : non classé

    $corrected = analysisProduct(['sku' => 'U-CORRIGE']);
    $sale = analysisSale($corrected, 10);
    analysisMovement($corrected, MovementType::Correction, 10, MovementReason::Correction, now()->subDays(4), [
        'corrects_movement_id' => $sale->id,
        'quantity_after' => 1010,
    ]);

    expect(app(AbcClassifier::class)->classify()->pluck('product.sku')->all())->toBe(['X-VENDU', 'T-INTERNE']);
});

test('le résumé donne le nombre de produits, la valeur et la part de chaque classe', function () {
    abcScenario([600, 250, 80, 50, 20]);
    $classifier = app(AbcClassifier::class);

    expect($classifier->summary($classifier->classify()))->toBe([
        'A' => ['products' => 2, 'value' => '850.00', 'share' => 85.0],
        'B' => ['products' => 2, 'value' => '130.00', 'share' => 13.0],
        'C' => ['products' => 1, 'value' => '20.00', 'share' => 2.0],
    ]);

    expect($classifier->summary(collect()))->toBe([
        'A' => ['products' => 0, 'value' => '0.00', 'share' => 0.0],
        'B' => ['products' => 0, 'value' => '0.00', 'share' => 0.0],
        'C' => ['products' => 0, 'value' => '0.00', 'share' => 0.0],
    ]);
});

test('sans aucune consommation, rien n\'est classé', function () {
    analysisProduct();

    expect(app(AbcClassifier::class)->classify())->toBeEmpty();
});

// ------------------------------------------------------------------ Valorisation

test('la valeur du stock est calculée en décimal exact, hors produits supprimés', function () {
    analysisProduct(['quantity' => 10, 'purchase_price' => '12.50']);                       // 125.00
    analysisProduct(['quantity' => 3, 'purchase_price' => '99.99']);                        // 299.97
    analysisProduct(['quantity' => 0, 'purchase_price' => '500.00']);                       // 0
    analysisProduct(['quantity' => 2, 'purchase_price' => '5.00', 'is_active' => false]);   // 10.00 : inactif mais en stock
    analysisProduct(['quantity' => 100, 'purchase_price' => '1.00'])->delete();             // supprimé : exclu

    $summary = app(StockValuation::class)->summary();

    expect($summary['value'])->toBe('434.97')
        ->and($summary['units'])->toBe(15)
        ->and($summary['in_stock'])->toBe(3)
        ->and($summary['products'])->toBe(4);
});

test('la valeur des lots expirés non retirés est isolée', function () {
    $product = Product::factory()->tracked(false)->create(['quantity' => 0, 'purchase_price' => '100.00']);
    $user = User::factory()->create();
    $service = app(StockMovementService::class);

    $service->recordEntry($product, 4, $user, lotNumber: 'EXP', expiresAt: now()->addDays(5));
    $service->recordEntry($product, 6, $user, lotNumber: 'OK', expiresAt: now()->addDays(50));
    StockLot::where('lot_number', 'EXP')->update(['expires_at' => today()->subDay()->toDateString()]);

    $summary = app(StockValuation::class)->summary();

    expect($summary['expired_units'])->toBe(4)
        ->and($summary['expired_value'])->toBe('400.00')
        ->and($summary['value'])->toBe('1000.00');
});

test('une base vide a une valeur nulle', function () {
    expect(app(StockValuation::class)->summary())->toBe([
        'products' => 0, 'in_stock' => 0, 'units' => 0, 'value' => '0.00',
        'expired_units' => 0, 'expired_value' => '0.00',
    ]);
});

test('la valeur est répartie par catégorie, la plus chère d\'abord', function () {
    $aliments = Category::factory()->create(['name' => 'Aliments']);
    $materiel = Category::factory()->create(['name' => 'Matériel']);

    analysisProduct(['category_id' => $aliments->id, 'quantity' => 10, 'purchase_price' => '42.50']);   // 425.00
    analysisProduct(['category_id' => $materiel->id, 'quantity' => 5, 'purchase_price' => '15.00']);    // 75.00
    analysisProduct(['category_id' => $materiel->id, 'quantity' => 0, 'purchase_price' => '15.00']);

    $rows = app(StockValuation::class)->byCategory();

    expect($rows->pluck('name')->all())->toBe(['Aliments', 'Matériel'])
        ->and($rows->pluck('value')->all())->toBe(['425.00', '75.00'])
        ->and($rows->pluck('units')->all())->toBe([10, 5])
        ->and($rows->pluck('in_stock')->all())->toBe([1, 1])
        ->and($rows->pluck('products')->all())->toBe([1, 2])
        ->and($rows->pluck('cents')->all())->toBe([42500, 7500]);
});

test('le coût des pertes est calculé par motif, au prix d\'achat, hors mouvements corrigés', function () {
    $product = analysisProduct(['purchase_price' => '10.00']);

    analysisMovement($product, MovementType::Exit, 5, MovementReason::Expiry, now()->subDays(10));
    analysisMovement($product, MovementType::Exit, 2, MovementReason::Breakage, now()->subDays(9));
    analysisMovement($product, MovementType::Exit, 1, MovementReason::Loss, now()->subDays(8));
    analysisMovement($product, MovementType::Exit, 3, MovementReason::Theft, now()->subDays(7));
    analysisSale($product, 100, 6);                                                                    // une vente n'est pas une perte
    $corrected = analysisMovement($product, MovementType::Exit, 50, MovementReason::Loss, now()->subDays(5));
    analysisMovement($product, MovementType::Correction, 50, MovementReason::Correction, now()->subDays(4), [
        'corrects_movement_id' => $corrected->id,
        'quantity_after' => 1050,
    ]);
    analysisMovement($product, MovementType::Exit, 70, MovementReason::Loss, now()->subDays(200));    // hors période
    analysisMovement($product, MovementType::Exit, 9, MovementReason::Theft, now()->subDays(3), [
        'status' => MovementStatus::Conflict,                                                         // non appliqué
    ]);

    $result = app(StockValuation::class)->lossCost(90);

    expect($result['rows']->pluck('label')->all())->toBe(['Péremption', 'Casse', 'Perte', 'Vol constaté'])
        ->and($result['rows']->pluck('units')->all())->toBe([5, 2, 1, 3])
        ->and($result['rows']->pluck('movements')->all())->toBe([1, 1, 1, 1])
        ->and($result['rows']->pluck('value')->all())->toBe(['50.00', '20.00', '10.00', '30.00'])
        ->and($result['rows']->pluck('width')->all())->toBe([100, 40, 20, 60])
        ->and($result['total_value'])->toBe('110.00')
        ->and($result['total_cents'])->toBe(11000);

    // Sur 365 jours, la perte ancienne entre dans le calcul.
    $year = app(StockValuation::class)->lossCost(365);

    expect($year['rows']->firstWhere('label', 'Perte')['value'])->toBe('710.00')
        ->and($year['total_value'])->toBe('810.00');
});

test('les quatre motifs de pertes sont toujours présents, à zéro sans mouvement', function () {
    $result = app(StockValuation::class)->lossCost(30);

    expect($result['rows'])->toHaveCount(4)
        ->and($result['rows']->pluck('value')->unique()->all())->toBe(['0.00'])
        ->and($result['rows']->pluck('width')->unique()->all())->toBe([0])
        ->and($result['total_value'])->toBe('0.00');
});

// ------------------------------------------------------------------ Écrans et droits

test("seul l'administrateur accède aux écrans d'analyse", function () {
    foreach (['analysis.valuation', 'analysis.abc'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    $manager = User::factory()->create();
    foreach (['analysis.valuation', 'analysis.abc'] as $route) {
        $this->actingAs($manager)->get(route($route))->assertForbidden();
    }

    $admin = User::factory()->admin()->create();
    foreach (['analysis.valuation', 'analysis.abc'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk();
    }
});

test("un administrateur désactivé n'a pas accès à l'analyse", function () {
    $inactive = User::factory()->admin()->inactive()->create();

    expect($inactive->can('view-analysis'))->toBeFalse();
});

test('la navigation propose Péremptions à tous et Analyse à l\'administrateur seulement', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertSee(route('expiries.index'), false)
        ->assertDontSee(route('analysis.valuation'), false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('profile.edit'))
        ->assertSee(route('expiries.index'), false)
        ->assertSee(route('analysis.valuation'), false);
});

test('la page de valorisation affiche les montants et les barres du coût des pertes', function () {
    $category = Category::factory()->create(['name' => 'Aliments']);
    $product = analysisProduct(['category_id' => $category->id, 'quantity' => 10, 'purchase_price' => '12.50']);
    analysisMovement($product, MovementType::Exit, 4, MovementReason::Breakage, now()->subDays(3));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('analysis.valuation'))
        ->assertOk()
        ->assertSee('Valeur du stock')
        ->assertSee('125,00')                  // 10 × 12,50
        ->assertSee('Aliments')
        ->assertSee('Casse')
        ->assertSee('50,00')                   // 4 × 12,50
        ->assertSee('width: 100%', false);
});

test('la période du coût des pertes est limitée aux valeurs proposées', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('analysis.valuation', ['days' => 45]))->assertSessionHasErrors('days');
    $this->actingAs($admin)->get(route('analysis.valuation', ['days' => 30]))
        ->assertOk()->assertViewHas('days', 30);
    $this->actingAs($admin)->get(route('analysis.valuation'))->assertViewHas('days', 90);
});

test('la page ABC affiche les classes, les produits dormants et le capital immobilisé', function () {
    abcScenario([600, 250, 80, 50, 20]);

    $dormant = analysisProduct(['sku' => 'DORM-1', 'quantity' => 10, 'purchase_price' => '12.50']);
    analysisMovement($dormant, MovementType::Entry, 100, MovementReason::Reception, now()->subDays(100));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('analysis.abc'))
        ->assertOk()
        ->assertSee('Classe A')->assertSee('Classe B')->assertSee('Classe C')
        ->assertSee('P1')->assertSee('600,00')
        ->assertSee('Produits dormants')
        ->assertSee('DORM-1')
        ->assertSee('Capital immobilisé')
        ->assertSee('125,00')
        ->assertSee('100 j');
});

test('les produits actifs sans consommation sont comptés comme non classés', function () {
    abcScenario([100]);
    analysisProduct();
    analysisProduct();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('analysis.abc'))
        ->assertViewHas('unclassified', 2)
        ->assertSee('2 produit(s) actif(s) non classé(s)');
});

test('la page ABC propose des états vides explicites', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('analysis.abc'))
        ->assertOk()
        ->assertSee('rien à classer')
        ->assertSee('Aucun produit dormant');
});

test('le classement ABC est paginé', function () {
    abcScenario(array_fill(0, 30, 100));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('analysis.abc'))
        ->assertViewHas('classified', fn ($page) => $page->count() === 25 && $page->total() === 30);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('analysis.abc', ['abc_page' => 2]))
        ->assertViewHas('classified', fn ($page) => $page->count() === 5);
});

test("les noms de produits sont échappés dans les écrans d'analyse", function () {
    $product = analysisProduct(['name' => '<script>alert(1)</script>']);
    analysisSale($product, 10);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('analysis.abc'))
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});