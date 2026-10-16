<?php

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('le tableau de bord s\'affiche sur une base vide', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Aucun produit à réapprovisionner')
        ->assertSee('Aucun mouvement enregistré');
});

test('les compteurs de produits viennent de la base et ignorent les produits inactifs', function () {
    Product::factory()->count(3)->create();
    Product::factory()->lowStock()->count(2)->create();
    Product::factory()->outOfStock()->create();
    Product::factory()->inactive()->lowStock()->create();
    Product::factory()->inactive()->outOfStock()->create();

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertViewHas('stats', fn ($s) => $s['products'] === 6 && $s['low'] === 2 && $s['out'] === 1);

    // Les valeurs ne sont pas figées : elles suivent la base.
    Product::factory()->lowStock()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertViewHas('stats', fn ($s) => $s['products'] === 7 && $s['low'] === 3 && $s['out'] === 1);
});

test('les compteurs de catégories et de fournisseurs ne comptent que les éléments actifs', function () {
    Category::factory()->count(2)->create();
    Category::factory()->create(['is_active' => false]);
    Supplier::factory()->count(3)->create();
    Supplier::factory()->create(['is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertViewHas('stats', fn ($s) => $s['categories'] === 2 && $s['suppliers'] === 3);
});

test('le nombre de mouvements dépend de la période choisie', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 0]);
    $service = app(StockMovementService::class);

    $service->recordEntry($product, 50, $user, occurredAt: now()->subDays(40));
    $service->recordExit($product, 5, $user, MovementReason::Sale, occurredAt: now()->subDays(20));
    $service->recordExit($product, 5, $user, MovementReason::Sale, occurredAt: now()->subDays(3));

    $movementsFor = fn (array $query) => $this->actingAs($user)
        ->get(route('dashboard', $query))
        ->viewData('stats')['movements'];

    expect($movementsFor([]))->toBe(2)                  // 30 jours par défaut
        ->and($movementsFor(['days' => 7]))->toBe(1)
        ->and($movementsFor(['days' => 30]))->toBe(2)
        ->and($movementsFor(['days' => 90]))->toBe(3)
        ->and($movementsFor(['days' => 15]))->toBe(2);  // période invalide : retour à 30 jours
});

test('le détail par type de mouvement est correct', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['quantity' => 0]);
    $service = app(StockMovementService::class);

    $service->recordEntry($product, 50, $user);
    $service->recordExit($product, 5, $user, MovementReason::Sale);
    $service->recordExit($product, 5, $user, MovementReason::Loss);
    $service->recordAdjustment($product, 30, $user, 'Inventaire');

    $this->actingAs($user)->get(route('dashboard'))
        ->assertViewHas('byType', fn ($byType) => $byType === [
            'entry' => 1, 'exit' => 2, 'adjustment' => 1, 'correction' => 0,
        ]);
});

test('seuls les mouvements appliqués sont comptés', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    StockMovement::create([
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

    $this->actingAs($user)->get(route('dashboard'))
        ->assertViewHas('stats', fn ($s) => $s['movements'] === 0);
});

test('la liste de réapprovisionnement est triée et limitée à cinq produits actifs', function () {
    Product::factory()->create(['name' => 'Produit normal', 'quantity' => 50, 'minimum_stock' => 10]);
    Product::factory()->inactive()->outOfStock()->create(['name' => 'Produit inactif']);
    Product::factory()->create(['name' => 'Faible 8', 'quantity' => 8, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Rupture', 'quantity' => 0, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Faible 2', 'quantity' => 2, 'minimum_stock' => 10]);

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertViewHas('restock', fn ($restock) => $restock->pluck('name')->all()
            === ['Rupture', 'Faible 2', 'Faible 8']);

    Product::factory()->lowStock()->count(6)->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertViewHas('restock', fn ($restock) => $restock->count() === 5);
});

test('les derniers mouvements sont affichés, du plus récent au plus ancien, limités à huit', function () {
    $user = User::factory()->create();
    $service = app(StockMovementService::class);

    foreach (range(1, 10) as $i) {
        $service->recordEntry(
            Product::factory()->create(['name' => "Produit {$i}", 'quantity' => 0]),
            $i,
            $user,
            occurredAt: now()->subHours(11 - $i),
        );
    }

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('recentMovements', fn ($movements) => $movements->count() === 8
            && $movements->first()->product->name === 'Produit 10'
            && $movements->last()->product->name === 'Produit 3');
});

test('les raccourcis dépendent des droits', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertSee(route('products.create'), false)
        ->assertSee(route('alerts.index'), false);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee(route('products.create'), false)
        ->assertSee(route('alerts.index'), false);
});

test('les compteurs de stock sont calculés en une seule requête', function () {
    Product::factory()->count(5)->create();
    Product::factory()->lowStock()->count(3)->create();
    Product::factory()->outOfStock()->count(2)->create();

    DB::enableQueryLog();
    $counts = Product::stockCounts();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($counts)->toBe(['active' => 10, 'low' => 3, 'out' => 2]);
    expect($queries)->toBe(1);
});

test('le tableau de bord ne déclenche pas de requêtes en cascade', function () {
    $user = User::factory()->create();
    $service = app(StockMovementService::class);

    foreach (range(1, 10) as $i) {
        $service->recordEntry(Product::factory()->lowStock()->create(['quantity' => 0]), 5, $user);
    }

    DB::enableQueryLog();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(15);
});