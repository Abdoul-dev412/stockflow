<?php

use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;

test('le statut de stock suit les règles du cahier des charges', function () {
    $status = fn (int $quantity, int $minimum) => Product::factory()
        ->make(['quantity' => $quantity, 'minimum_stock' => $minimum])
        ->stockStatus();

    expect($status(50, 10))->toBe(StockStatus::Normal);
    expect($status(11, 10))->toBe(StockStatus::Normal);
    expect($status(10, 10))->toBe(StockStatus::Low);   // seuil atteint
    expect($status(1, 10))->toBe(StockStatus::Low);
    expect($status(0, 10))->toBe(StockStatus::OutOfStock);
    expect($status(5, 0))->toBe(StockStatus::Normal);
});

test('les scopes de stock faible et de rupture filtrent correctement', function () {
    Product::factory()->create(['quantity' => 50, 'minimum_stock' => 10]);
    Product::factory()->lowStock()->create();
    Product::factory()->outOfStock()->create();

    expect(Product::lowStock()->count())->toBe(1);
    expect(Product::outOfStock()->count())->toBe(1);
});

test('un SKU en double est refusé par la base', function () {
    Product::factory()->create(['sku' => 'ABC-0001']);

    expect(fn () => Product::factory()->create(['sku' => 'ABC-0001']))
        ->toThrow(QueryException::class);
});

test('une quantité négative est refusée par la base', function () {
    expect(fn () => Product::factory()->create(['quantity' => -1]))
        ->toThrow(QueryException::class);
});

test('un prix négatif est refusé par la base', function () {
    expect(fn () => Product::factory()->create(['purchase_price' => -5]))
        ->toThrow(QueryException::class);
});

test('une catégorie utilisée par des produits ne peut pas être supprimée', function () {
    $product = Product::factory()->create();

    expect(fn () => $product->category->delete())
        ->toThrow(QueryException::class);
});

test('un produit supprimé reste en base grâce à la suppression logique', function () {
    $product = Product::factory()->create();

    $product->delete();

    expect(Product::count())->toBe(0);
    expect(Product::withTrashed()->count())->toBe(1);
});

test('la quantité ne peut pas être fixée par affectation de masse', function () {
    $product = Product::create([
        'sku' => 'MASS-0001',
        'name' => 'Test',
        'category_id' => Category::factory()->create()->id,
        'quantity' => 99,
    ]);

    expect($product->fresh()->quantity)->toBe(0);
});