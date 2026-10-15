<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function validProduct(array $overrides = []): array
{
    return array_merge([
        'sku' => 'ALI-001',
        'name' => 'Aliment démarrage 25 kg',
        'category_id' => Category::factory()->create()->id,
        'unit' => 'sac',
        'purchase_price' => '18500.00',
        'minimum_stock' => 10,
    ], $overrides);
}

test('un visiteur ne peut pas voir les produits', function () {
    $this->get(route('products.index'))->assertRedirect(route('login'));
});

test('un gestionnaire peut consulter la liste et la fiche', function () {
    $product = Product::factory()->create(['name' => 'Aliment démarrage']);
    $manager = User::factory()->create();

    $this->actingAs($manager)->get(route('products.index'))
        ->assertOk()->assertSee('Aliment démarrage');

    $this->actingAs($manager)->get(route('products.show', $product))
        ->assertOk()->assertSee('Aliment démarrage');
});

test('un gestionnaire ne peut ni créer, ni modifier, ni désactiver', function () {
    $manager = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Original']);

    $this->actingAs($manager)->get(route('products.create'))->assertForbidden();

    $this->actingAs($manager)->post(route('products.store'), validProduct())->assertForbidden();
    expect(Product::count())->toBe(1);

    $this->actingAs($manager)
        ->put(route('products.update', $product), validProduct(['name' => 'Piraté']))
        ->assertForbidden();
    expect($product->fresh()->name)->toBe('Original');

    $this->actingAs($manager)->patch(route('products.toggle', $product))->assertForbidden();
    expect($product->fresh()->is_active)->toBeTrue();
});

test('un administrateur peut créer un produit, avec un stock initial à zéro', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('products.store'), validProduct());

    $product = Product::where('sku', 'ALI-001')->firstOrFail();

    $response->assertRedirect(route('products.show', $product));
    expect($product->fresh()->quantity)->toBe(0);
});

test('la quantité envoyée dans le formulaire est ignorée', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('products.store'), validProduct(['quantity' => 500]));

    expect(Product::first()->fresh()->quantity)->toBe(0);
});

test('la modification ne peut pas changer la quantité', function () {
    $product = Product::factory()->create(['quantity' => 40]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('products.update', $product), validProduct([
            'sku' => $product->sku,
            'category_id' => $product->category_id,
            'quantity' => 999,
        ]))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->quantity)->toBe(40);
});

test('le SKU est normalisé en majuscules', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('products.store'), validProduct(['sku' => ' ali-001 ']))
        ->assertSessionHasNoErrors();

    expect(Product::first()->sku)->toBe('ALI-001');
});

test('un SKU déjà utilisé est refusé, même en minuscules', function () {
    Product::factory()->create(['sku' => 'ALI-001']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('products.store'), validProduct(['sku' => 'ali-001']))
        ->assertSessionHasErrors('sku');

    expect(Product::count())->toBe(1);
});

test("un SKU d'un produit supprimé logiquement reste réservé", function () {
    Product::factory()->create(['sku' => 'ALI-001'])->delete();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('products.store'), validProduct(['sku' => 'ALI-001']))
        ->assertSessionHasErrors('sku');
});

test('le code-barres est facultatif mais unique', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('products.store'), validProduct(['sku' => 'A-1', 'barcode' => '']))
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)
        ->post(route('products.store'), validProduct(['sku' => 'A-2', 'barcode' => '']))
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->post(route('products.store'), validProduct(['sku' => 'A-3', 'barcode' => '6001234567890']))
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)
        ->post(route('products.store'), validProduct(['sku' => 'A-4', 'barcode' => '6001234567890']))
        ->assertSessionHasErrors('barcode');

    expect(Product::count())->toBe(3);
});

test('les prix et le seuil négatifs ou invalides sont refusés', function () {
    $admin = User::factory()->admin()->create();

    foreach ([
        ['purchase_price' => -1],
        ['purchase_price' => '12.345'],
        ['purchase_price' => 'abc'],
        ['sale_price' => -5],
        ['minimum_stock' => -1],
        ['minimum_stock' => 2.5],
    ] as $invalid) {
        $this->actingAs($admin)
            ->post(route('products.store'), validProduct($invalid))
            ->assertSessionHasErrors(array_key_first($invalid));
    }

    expect(Product::count())->toBe(0);
});

test('le prix de vente est facultatif', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('products.store'), validProduct(['sale_price' => '']))
        ->assertSessionHasNoErrors();

    expect(Product::first()->sale_price)->toBeNull();
});

test('une catégorie ou un fournisseur inactif ne peut pas être choisi', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('products.store'), validProduct([
            'category_id' => Category::factory()->create(['is_active' => false])->id,
        ]))
        ->assertSessionHasErrors('category_id');

    $this->actingAs($admin)
        ->post(route('products.store'), validProduct([
            'supplier_id' => Supplier::factory()->create(['is_active' => false])->id,
        ]))
        ->assertSessionHasErrors('supplier_id');

    expect(Product::count())->toBe(0);
});

test('les filtres de catégorie, de statut et de stock se combinent', function () {
    $category = Category::factory()->create();
    Product::factory()->create(['name' => 'Cible', 'category_id' => $category->id, 'quantity' => 2, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Autre catégorie', 'quantity' => 2, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Même catégorie stock normal', 'category_id' => $category->id, 'quantity' => 90, 'minimum_stock' => 10]);
    Product::factory()->inactive()->create(['name' => 'Produit retiré du catalogue', 'category_id' => $category->id, 'quantity' => 2, 'minimum_stock' => 10]);

    $this->actingAs(User::factory()->create())
        ->get(route('products.index', ['category' => $category->id, 'stock' => 'low', 'status' => 'active']))
        ->assertSee('Cible')
        ->assertDontSee('Autre catégorie')
        ->assertDontSee('Même catégorie stock normal')
        ->assertDontSee('Produit retiré du catalogue');
});

test('un produit peut être modifié en conservant son SKU', function () {
    $product = Product::factory()->create(['sku' => 'ALI-001']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('products.update', $product), validProduct([
            'sku' => 'ALI-001',
            'category_id' => $product->category_id,
            'name' => 'Nouveau nom',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('products.show', $product));

    expect($product->fresh()->name)->toBe('Nouveau nom');
});

test('un administrateur peut désactiver puis réactiver un produit', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();

    $this->actingAs($admin)->patch(route('products.toggle', $product));
    expect($product->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch(route('products.toggle', $product));
    expect($product->fresh()->is_active)->toBeTrue();
});

test('la recherche couvre le nom, le SKU et le code-barres', function () {
    Product::factory()->create(['name' => 'Aliment ponte', 'sku' => 'ALI-010', 'barcode' => '111222']);
    Product::factory()->create(['name' => 'Vaccin Newcastle', 'sku' => 'VAC-010', 'barcode' => '333444']);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('products.index', ['search' => 'ponte']))
        ->assertSee('Aliment ponte')->assertDontSee('Vaccin Newcastle');

    $this->actingAs($user)->get(route('products.index', ['search' => 'VAC-010']))
        ->assertSee('Vaccin Newcastle')->assertDontSee('Aliment ponte');

    $this->actingAs($user)->get(route('products.index', ['search' => '111222']))
        ->assertSee('Aliment ponte')->assertDontSee('Vaccin Newcastle');
});

test('le filtre de niveau de stock distingue normal, faible et rupture', function () {
    Product::factory()->create(['name' => 'Produit normal', 'quantity' => 50, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Produit faible', 'quantity' => 5, 'minimum_stock' => 10]);
    Product::factory()->create(['name' => 'Produit rupture', 'quantity' => 0, 'minimum_stock' => 10]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('products.index', ['stock' => 'normal']))
        ->assertSee('Produit normal')->assertDontSee('Produit faible')->assertDontSee('Produit rupture');

    $this->actingAs($user)->get(route('products.index', ['stock' => 'low']))
        ->assertSee('Produit faible')->assertDontSee('Produit normal')->assertDontSee('Produit rupture');

    $this->actingAs($user)->get(route('products.index', ['stock' => 'out']))
        ->assertSee('Produit rupture')->assertDontSee('Produit normal')->assertDontSee('Produit faible');
});

test('le tri est limité à une liste blanche de colonnes', function () {
    Product::factory()->create(['name' => 'Gros stock', 'quantity' => 100]);
    Product::factory()->create(['name' => 'Petit stock', 'quantity' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('products.index', ['sort' => 'quantity', 'direction' => 'desc']))
        ->assertSeeInOrder(['Gros stock', 'Petit stock']);

    $this->actingAs($user)
        ->get(route('products.index', ['sort' => 'quantity', 'direction' => 'asc']))
        ->assertSeeInOrder(['Petit stock', 'Gros stock']);

    $this->actingAs($user)
        ->get(route('products.index', ['sort' => 'name; DROP TABLE products', 'direction' => 'sideways']))
        ->assertOk();

    expect(Product::count())->toBe(2);
});

test('la liste est paginée côté serveur', function () {
    Product::factory()->count(20)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('products.index'))
        ->assertOk()
        ->assertViewHas('products', fn ($products) => $products->count() === 15 && $products->total() === 20);
});

test('la liste ne déclenche pas de requêtes en cascade', function () {
    Product::factory()->count(20)->create(['supplier_id' => Supplier::factory()]);
    $user = User::factory()->create();

    DB::enableQueryLog();
    $this->actingAs($user)->get(route('products.index'))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(10);
});