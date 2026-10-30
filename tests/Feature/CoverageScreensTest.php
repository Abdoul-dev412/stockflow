<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Enums\MovementReason;
use App\Services\StockMovementService;

function screenProduct(int $quantity, int $consumed = 60, array $attributes = []): Product
{
    $product = Product::factory()->create(array_merge(['quantity' => 0], $attributes));
    $user = User::factory()->create();
    $service = app(StockMovementService::class);

    $service->recordEntry($product, $quantity + $consumed, $user, occurredAt: now()->subDays(40));
    $service->recordExit($product, $consumed, $user, MovementReason::Sale, occurredAt: now()->subDays(5));

    return $product->fresh();
}

function coveragePayload(array $overrides = []): array
{
    return array_merge([
        'sku' => 'ALI-900',
        'name' => 'Aliment test',
        'category_id' => Category::factory()->create()->id,
        'unit' => 'sac',
        'purchase_price' => '18500.00',
        'minimum_stock' => 10,
    ], $overrides);
}

test('la fiche produit affiche la couverture estimée', function () {
    $product = screenProduct(13);

    $this->actingAs(User::factory()->create())
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('Couverture du stock')
        ->assertSee('Critique')
        ->assertSee('2,00')
        ->assertSee('Rupture estimée');
});

test('la fiche d\'un produit sans historique explique pourquoi la couverture est indéterminée', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('products.show', Product::factory()->create(['quantity' => 5])))
        ->assertOk()
        ->assertSee('Indéterminée')
        ->assertSee('Historique insuffisant');
});

test('la liste des produits affiche une colonne de couverture', function () {
    screenProduct(13, attributes: ['name' => 'Produit pressé']);

    $this->actingAs(User::factory()->create())
        ->get(route('products.index'))
        ->assertOk()
        ->assertSee('Couverture')
        ->assertSee('Critique');
});

test('un produit au stock normal mais à couverture critique figure dans les alertes de couverture', function () {
    // 13 en stock pour un seuil de 5 : aucune alerte classique, mais 6,5 jours de stock.
    screenProduct(13, attributes: ['name' => 'Aliment pressé', 'minimum_stock' => 5]);
    screenProduct(300, attributes: ['name' => 'Aliment tranquille', 'minimum_stock' => 5]);

    $this->actingAs(User::factory()->create())
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertSee('Couverture critique')
        ->assertViewHas('products', fn ($products) => $products->total() === 0)
        ->assertViewHas('criticalCoverage', fn ($rows) => $rows->count() === 1
            && $rows->first()['product']->name === 'Aliment pressé');
});

test('les produits inactifs sont absents des alertes de couverture', function () {
    screenProduct(13)->update(['is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('alerts.index'))
        ->assertViewHas('criticalCoverage', fn ($rows) => $rows->isEmpty());
});

test('le tableau de bord compte les produits en couverture critique', function () {
    screenProduct(13);
    screenProduct(14);
    screenProduct(300);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertViewHas('stats', fn ($stats) => $stats['critical_coverage'] === 1);
});

test('la couverture cible vaut 30 jours par défaut et se modifie', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('products.store'), coveragePayload())->assertSessionHasNoErrors();
    expect(Product::firstOrFail()->target_cover_days)->toBe(30);

    $this->actingAs($admin)
        ->post(route('products.store'), coveragePayload(['sku' => 'ALI-901', 'target_cover_days' => 45]))
        ->assertSessionHasNoErrors();
    expect(Product::where('sku', 'ALI-901')->firstOrFail()->target_cover_days)->toBe(45);
});

test('la couverture cible doit être un entier entre 1 et 365', function () {
    $admin = User::factory()->admin()->create();

    foreach ([0, -5, 366, 'abc', 2.5] as $invalid) {
        $this->actingAs($admin)
            ->post(route('products.store'), coveragePayload(['target_cover_days' => $invalid]))
            ->assertSessionHasErrors('target_cover_days');
    }

    expect(Product::count())->toBe(0);
});

test('une modification sans le champ conserve la couverture cible du produit', function () {
    $product = Product::factory()->create(['sku' => 'ALI-902', 'target_cover_days' => 60]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('products.update', $product), coveragePayload([
            'sku' => 'ALI-902',
            'category_id' => $product->category_id,
        ]))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->target_cover_days)->toBe(60);
});