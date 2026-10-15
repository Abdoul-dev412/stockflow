<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;

test('un visiteur ne peut pas voir les catégories', function () {
    $this->get(route('categories.index'))->assertRedirect(route('login'));
});

test('un gestionnaire peut consulter les catégories', function () {
    Category::factory()->create(['name' => 'Aliments']);

    $this->actingAs(User::factory()->create())
        ->get(route('categories.index'))
        ->assertOk()
        ->assertSee('Aliments');
});

test('un gestionnaire ne peut ni créer, ni modifier, ni désactiver', function () {
    $manager = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Aliments']);

    $this->actingAs($manager)->get(route('categories.create'))->assertForbidden();

    $this->actingAs($manager)
        ->post(route('categories.store'), ['name' => 'Nouvelle'])
        ->assertForbidden();
    expect(Category::count())->toBe(1);

    $this->actingAs($manager)
        ->put(route('categories.update', $category), ['name' => 'Piratée'])
        ->assertForbidden();
    expect($category->fresh()->name)->toBe('Aliments');

    $this->actingAs($manager)
        ->patch(route('categories.toggle', $category))
        ->assertForbidden();
    expect($category->fresh()->is_active)->toBeTrue();
});

test('un administrateur peut créer une catégorie', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('categories.store'), [
            'name' => 'Vaccins',
            'description' => 'Produits de santé',
        ])
        ->assertRedirect(route('categories.index'));

    expect(Category::where('name', 'Vaccins')->exists())->toBeTrue();
});

test('le nom est obligatoire et doit être unique', function () {
    Category::factory()->create(['name' => 'Aliments']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('categories.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    $this->actingAs($admin)
        ->post(route('categories.store'), ['name' => 'Aliments'])
        ->assertSessionHasErrors('name');

    expect(Category::count())->toBe(1);
});

test('un administrateur peut modifier une catégorie en conservant son nom', function () {
    $category = Category::factory()->create(['name' => 'Aliments']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('categories.update', $category), [
            'name' => 'Aliments',
            'description' => 'Mise à jour',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('categories.index'));

    expect($category->fresh()->description)->toBe('Mise à jour');
});

test('un administrateur peut désactiver puis réactiver une catégorie', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();

    $this->actingAs($admin)->patch(route('categories.toggle', $category));
    expect($category->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch(route('categories.toggle', $category));
    expect($category->fresh()->is_active)->toBeTrue();
});

test('la recherche et le filtre de statut fonctionnent', function () {
    Category::factory()->create(['name' => 'Vaccins']);
    Category::factory()->create(['name' => 'Aliments']);
    Category::factory()->create(['name' => 'Archive', 'is_active' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('categories.index', ['search' => 'Vacc']))
        ->assertSee('Vaccins')
        ->assertDontSee('Aliments');

    $this->actingAs($user)->get(route('categories.index', ['status' => 'inactive']))
        ->assertSee('Archive')
        ->assertDontSee('Vaccins');
});

test('la fiche d\'une catégorie affiche ses produits', function () {
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id, 'name' => 'Aliment démarrage']);

    $this->actingAs(User::factory()->create())
        ->get(route('categories.show', $category))
        ->assertOk()
        ->assertSee('Aliment démarrage');
});