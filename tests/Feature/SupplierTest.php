<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;

function validSupplier(array $overrides = []): array
{
    return array_merge([
        'name' => 'Agro Plus',
        'contact_name' => 'Awa Traoré',
        'email' => 'contact@agroplus.test',
        'phone' => '+22670123456',
        'lead_time_days' => 5,
    ], $overrides);
}

test('un visiteur ne peut pas voir les fournisseurs', function () {
    $this->get(route('suppliers.index'))->assertRedirect(route('login'));
});

test('un gestionnaire peut consulter les fournisseurs', function () {
    Supplier::factory()->create(['name' => 'Agro Plus']);

    $this->actingAs(User::factory()->create())
        ->get(route('suppliers.index'))
        ->assertOk()
        ->assertSee('Agro Plus');
});

test('un gestionnaire ne peut ni créer, ni modifier, ni désactiver', function () {
    $manager = User::factory()->create();
    $supplier = Supplier::factory()->create(['name' => 'Agro Plus']);

    $this->actingAs($manager)->get(route('suppliers.create'))->assertForbidden();

    $this->actingAs($manager)
        ->post(route('suppliers.store'), validSupplier())
        ->assertForbidden();
    expect(Supplier::count())->toBe(1);

    $this->actingAs($manager)
        ->put(route('suppliers.update', $supplier), validSupplier(['name' => 'Piraté']))
        ->assertForbidden();
    expect($supplier->fresh()->name)->toBe('Agro Plus');

    $this->actingAs($manager)
        ->patch(route('suppliers.toggle', $supplier))
        ->assertForbidden();
    expect($supplier->fresh()->is_active)->toBeTrue();
});

test('un administrateur peut créer un fournisseur', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('suppliers.store'), validSupplier())
        ->assertRedirect(route('suppliers.index'));

    expect(Supplier::where('name', 'Agro Plus')->exists())->toBeTrue();
});

test('le nom et le délai sont obligatoires', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('suppliers.store'), validSupplier(['name' => '', 'lead_time_days' => '']))
        ->assertSessionHasErrors(['name', 'lead_time_days']);

    expect(Supplier::count())->toBe(0);
});

test('le délai doit être un entier entre 0 et 365', function () {
    $admin = User::factory()->admin()->create();

    foreach ([-1, 366, 'abc', 2.5] as $invalid) {
        $this->actingAs($admin)
            ->post(route('suppliers.store'), validSupplier(['lead_time_days' => $invalid]))
            ->assertSessionHasErrors('lead_time_days');
    }

    expect(Supplier::count())->toBe(0);
});

test('un téléphone hors format international est refusé', function () {
    $admin = User::factory()->admin()->create();

    foreach (['70123456', '+0123456789', '+22', 'abc', '+226-ab-12'] as $invalid) {
        $this->actingAs($admin)
            ->post(route('suppliers.store'), validSupplier(['phone' => $invalid]))
            ->assertSessionHasErrors('phone');
    }

    expect(Supplier::count())->toBe(0);
});

test('le téléphone est normalisé avant enregistrement', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('suppliers.store'), validSupplier(['phone' => '+226 70 12 34 56']))
        ->assertSessionHasNoErrors();

    expect(Supplier::first()->phone)->toBe('+22670123456');
});

test("l'email, le téléphone et le contact sont facultatifs", function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('suppliers.store'), [
            'name' => 'Minimal SA',
            'lead_time_days' => 3,
        ])
        ->assertSessionHasNoErrors();

    expect(Supplier::where('name', 'Minimal SA')->exists())->toBeTrue();
});

test('un administrateur peut modifier un fournisseur', function () {
    $supplier = Supplier::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('suppliers.update', $supplier), validSupplier(['name' => 'Nouveau nom']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('suppliers.index'));

    expect($supplier->fresh()->name)->toBe('Nouveau nom');
});

test('un administrateur peut désactiver puis réactiver un fournisseur', function () {
    $admin = User::factory()->admin()->create();
    $supplier = Supplier::factory()->create();

    $this->actingAs($admin)->patch(route('suppliers.toggle', $supplier));
    expect($supplier->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch(route('suppliers.toggle', $supplier));
    expect($supplier->fresh()->is_active)->toBeTrue();
});

test('la recherche couvre le nom, le contact et le filtre de statut', function () {
    Supplier::factory()->create(['name' => 'Agro Plus', 'contact_name' => 'Awa']);
    Supplier::factory()->create(['name' => 'Vétérinaire Sud', 'contact_name' => 'Ibrahim']);
    Supplier::factory()->create(['name' => 'Ancien Fournisseur', 'is_active' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('suppliers.index', ['search' => 'Agro']))
        ->assertSee('Agro Plus')
        ->assertDontSee('Vétérinaire Sud');

    $this->actingAs($user)->get(route('suppliers.index', ['search' => 'Ibrahim']))
        ->assertSee('Vétérinaire Sud')
        ->assertDontSee('Agro Plus');

    $this->actingAs($user)->get(route('suppliers.index', ['status' => 'inactive']))
        ->assertSee('Ancien Fournisseur')
        ->assertDontSee('Agro Plus');
});

test("la fiche d'un fournisseur affiche ses produits", function () {
    $supplier = Supplier::factory()->create();
    Product::factory()->create(['supplier_id' => $supplier->id, 'name' => 'Aliment démarrage']);

    $this->actingAs(User::factory()->create())
        ->get(route('suppliers.show', $supplier))
        ->assertOk()
        ->assertSee('Aliment démarrage');
});