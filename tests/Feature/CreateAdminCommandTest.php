<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function createAdminCommand(string $name, string $email, string $password, string $confirmation)
{
    return test()->artisan('stockflow:create-admin')
        ->expectsQuestion('Nom complet', $name)
        ->expectsQuestion('Adresse email', $email)
        ->expectsQuestion('Mot de passe (non affiché)', $password)
        ->expectsQuestion('Confirme le mot de passe', $confirmation);
}

test('la commande crée un administrateur actif, sans mot de passe temporaire', function () {
    createAdminCommand('Awa Traoré', 'Awa@Exemple.test', 'Mdp-Solide-2026', 'Mdp-Solide-2026')
        ->assertExitCode(0);

    $user = User::where('email', 'awa@exemple.test')->firstOrFail();

    expect($user->role)->toBe(Role::Admin)
        ->and($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Mdp-Solide-2026', $user->password))->toBeTrue();
});

test('la création est tracée sans aucun mot de passe', function () {
    createAdminCommand('Awa Traoré', 'awa@exemple.test', 'Mdp-Solide-2026', 'Mdp-Solide-2026')
        ->assertExitCode(0);

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe('user.created')
        ->and($log->user_id)->toBeNull()
        ->and($log->entity_type)->toBe('user')
        ->and(json_encode($log->details))->not->toContain('Mdp-Solide-2026');
});

test('la commande refuse un mot de passe faible, une confirmation différente ou un email déjà pris', function () {
    createAdminCommand('Awa', 'awa@exemple.test', 'court', 'court')->assertExitCode(1);
    createAdminCommand('Awa', 'awa@exemple.test', 'Mdp-Solide-2026', 'Autre-Mdp-2026')->assertExitCode(1);

    User::factory()->create(['email' => 'awa@exemple.test']);
    createAdminCommand('Awa', 'awa@exemple.test', 'Mdp-Solide-2026', 'Mdp-Solide-2026')->assertExitCode(1);

    expect(User::count())->toBe(1);
});