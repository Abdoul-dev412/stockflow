<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('toutes les routes applicatives exigent une authentification', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\')
            && ! str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\Auth\\'));

    // Garde-fou : le filtre doit bien trouver les routes métier.
    expect($routes->count())->toBeGreaterThan(40);

    $unprotected = $routes
        ->reject(fn ($route) => in_array('auth', $route->gatherMiddleware(), true))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

test('les pages privées sont inaccessibles sans connexion', function () {
    foreach (['dashboard', 'products.index', 'categories.index', 'suppliers.index', 'movements.index',
        'alerts.index', 'audit.index', 'users.index', 'profile.edit',
        'exports.products', 'exports.stock-levels', 'exports.movements'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
});

test('la connexion est bloquée après cinq échecs, même avec le bon mot de passe', function () {
    User::factory()->create(['email' => 'awa@exemple.test']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login'), ['email' => 'awa@exemple.test', 'password' => 'mauvais']);
    }

    $this->post(route('login'), ['email' => 'awa@exemple.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('les en-têtes de sécurité sont présents sur les pages, les erreurs et les exports', function () {
    $expected = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    $assertHeaders = function ($response) use ($expected) {
        foreach ($expected as $name => $value) {
            $response->assertHeader($name, $value);
        }
    };

    $assertHeaders($this->get(route('login')));

    $manager = User::factory()->create();
    $assertHeaders($this->actingAs($manager)->get(route('dashboard')));
    $assertHeaders($this->actingAs($manager)->get(route('users.index'))->assertForbidden());

    $assertHeaders($this->actingAs(User::factory()->admin()->create())->get(route('exports.products')));
});

test("le HSTS n'est envoyé que sur une connexion sécurisée", function () {
    $this->get('https://localhost/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
});

test("le seeder de démonstration refuse de s'exécuter en production", function () {
    $this->app->detectEnvironment(fn () => 'production');

    app(Database\Seeders\DatabaseSeeder::class)->run();

    expect(User::count())->toBe(0);
});