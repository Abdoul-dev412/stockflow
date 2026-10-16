<?php

use App\Enums\MovementReason;
use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;

test('un visiteur ne peut pas voir les alertes', function () {
    $this->get(route('alerts.index'))->assertRedirect(route('login'));
});

test('la page des alertes liste les produits actifs en stock faible ou en rupture', function () {
    Product::factory()->create(['name' => 'Produit normal', 'quantity' => 50, 'minimum_stock' => 10]);
    Product::factory()->lowStock()->create(['name' => 'Produit faible']);
    Product::factory()->outOfStock()->create(['name' => 'Produit en rupture']);
    Product::factory()->inactive()->lowStock()->create(['name' => 'Produit inactif faible']);

    $this->actingAs(User::factory()->create())
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertSee('Produit faible')
        ->assertSee('Produit en rupture')
        ->assertDontSee('Produit normal')
        ->assertDontSee('Produit inactif faible');
});

test('les seuils sont interprétés comme dans le cahier des charges', function () {
    Product::factory()->create(['name' => 'Pile au seuil', 'quantity' => 10, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Juste au-dessus', 'quantity' => 11, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Rupture sans seuil', 'quantity' => 0, 'minimum_stock' => 0]);
    Product::factory()->create(['name' => 'Sans seuil avec stock', 'quantity' => 5, 'minimum_stock' => 0]);

    $this->actingAs(User::factory()->create())
        ->get(route('alerts.index'))
        ->assertSee('Pile au seuil')
        ->assertSee('Rupture sans seuil')
        ->assertDontSee('Juste au-dessus')
        ->assertDontSee('Sans seuil avec stock')
        ->assertViewHas('products', fn ($products) => $products->pluck('name')->all()
            === ['Rupture sans seuil', 'Pile au seuil']);
});

test('le filtre de niveau distingue stock faible et rupture', function () {
    Product::factory()->lowStock()->create(['name' => 'Produit faible']);
    Product::factory()->outOfStock()->create(['name' => 'Produit en rupture']);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('alerts.index', ['level' => 'low']))
        ->assertSee('Produit faible')->assertDontSee('Produit en rupture');

    $this->actingAs($user)->get(route('alerts.index', ['level' => 'out']))
        ->assertSee('Produit en rupture')->assertDontSee('Produit faible');
});

test('un niveau invalide est refusé', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('alerts.index', ['level' => 'abc']))
        ->assertSessionHasErrors('level');
});

test('les filtres de catégorie et de fournisseur se combinent', function () {
    $category = Category::factory()->create();
    $supplier = Supplier::factory()->create();

    Product::factory()->lowStock()->create([
        'name' => 'Produit cible', 'category_id' => $category->id, 'supplier_id' => $supplier->id,
    ]);
    Product::factory()->lowStock()->create(['name' => 'Autre catégorie', 'supplier_id' => $supplier->id]);
    Product::factory()->lowStock()->create(['name' => 'Autre fournisseur', 'category_id' => $category->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('alerts.index', ['category' => $category->id, 'supplier' => $supplier->id]))
        ->assertSee('Produit cible')
        ->assertDontSee('Autre catégorie')
        ->assertDontSee('Autre fournisseur');
});

test('les alertes sont triées : ruptures d\'abord, puis les stocks les plus bas', function () {
    Product::factory()->create(['name' => 'Faible 8', 'quantity' => 8, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Rupture', 'quantity' => 0, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Faible 2', 'quantity' => 2, 'minimum_stock' => 10]);

    $this->actingAs(User::factory()->create())
        ->get(route('alerts.index'))
        ->assertViewHas('products', fn ($products) => $products->pluck('name')->all()
            === ['Rupture', 'Faible 2', 'Faible 8']);
});

test('une alerte change d\'état puis disparaît après une mise à jour du stock', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Aliment test', 'quantity' => 4, 'minimum_stock' => 10]);
    $service = app(StockMovementService::class);

    $this->actingAs($user)->get(route('alerts.index'))
        ->assertSee('Aliment test')
        ->assertViewHas('products', fn ($p) => $p->first()->stockStatus() === StockStatus::Low);

    $service->recordEntry($product, 20, $user);

    $this->actingAs($user)->get(route('alerts.index'))->assertDontSee('Aliment test');

    $service->recordExit($product, 24, $user, MovementReason::Sale);

    $this->actingAs($user)->get(route('alerts.index'))
        ->assertSee('Aliment test')
        ->assertViewHas('products', fn ($p) => $p->first()->stockStatus() === StockStatus::OutOfStock);
});

test('chaque alerte renvoie vers la fiche du produit et vers une entrée de stock', function () {
    $product = Product::factory()->lowStock()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('alerts.index'))
        ->assertSee(route('products.show', $product), false)
        ->assertSee(route('movements.create', [$product, 'entry']), false);
});

test('un message clair s\'affiche quand il n\'y a aucune alerte', function () {
    Product::factory()->create(['quantity' => 50, 'minimum_stock' => 10]);

    $this->actingAs(User::factory()->create())
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertSee('Aucune alerte');
});

test('la liste des alertes est paginée sans requêtes en cascade', function () {
    Product::factory()->lowStock()->count(20)->create(['supplier_id' => Supplier::factory()]);
    $user = User::factory()->create();

    DB::enableQueryLog();
    $response = $this->actingAs($user)->get(route('alerts.index'));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk()
        ->assertViewHas('products', fn ($p) => $p->count() === 15 && $p->total() === 20);

    expect($queries)->toBeLessThan(12);
});