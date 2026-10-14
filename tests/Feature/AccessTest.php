<?php

use App\Models\User;

test('un visiteur non connecté est redirigé vers la connexion', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('un utilisateur désactivé ne peut pas se connecter', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
});

test('un compte désactivé en cours de session est déconnecté', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
});