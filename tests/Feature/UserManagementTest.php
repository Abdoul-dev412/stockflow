<?php

use App\Enums\Role;
use App\Exceptions\UserManagementException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function newUserPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Awa Traoré',
        'email' => 'awa@exemple.test',
        'role' => 'manager',
        'password' => 'Mdp-Temporaire-2026',
    ], $overrides);
}

// ------------------------------------------------------------------ Accès

test("seul un administrateur accède à la gestion des utilisateurs", function () {
    $target = User::factory()->create();

    $this->get(route('users.index'))->assertRedirect(route('login'));

    $manager = User::factory()->create();
    $this->actingAs($manager)->get(route('users.index'))->assertForbidden();
    $this->actingAs($manager)->get(route('users.create'))->assertForbidden();
    $this->actingAs($manager)->post(route('users.store'), newUserPayload())->assertForbidden();
    $this->actingAs($manager)->get(route('users.edit', $target))->assertForbidden();
    $this->actingAs($manager)->put(route('users.update', $target), newUserPayload())->assertForbidden();
    $this->actingAs($manager)->patch(route('users.toggle', $target))->assertForbidden();
    $this->actingAs($manager)->get(route('users.password.edit', $target))->assertForbidden();
    $this->actingAs($manager)
        ->put(route('users.password.update', $target), ['password' => 'Autre-Mdp-2026'])
        ->assertForbidden();

    expect(User::count())->toBe(2);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('users.index'))->assertOk();
    $this->actingAs($admin)->get(route('users.create'))->assertOk();
});

test("le lien Utilisateurs n'apparaît que pour un administrateur", function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee(route('users.index'), false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertSee(route('users.index'), false);
});

// ------------------------------------------------------------------ Création

test('un administrateur crée un compte avec un mot de passe temporaire', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('users.store'), newUserPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    $user = User::where('email', 'awa@exemple.test')->firstOrFail();

    expect($user->role)->toBe(Role::Manager)
        ->and($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe('Mdp-Temporaire-2026')
        ->and(Hash::check('Mdp-Temporaire-2026', $user->password))->toBeTrue();
});

test("l'adresse email est normalisée et unique sans tenir compte de la casse", function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('users.store'), newUserPayload(['email' => '  Awa@Exemple.TEST ']))
        ->assertSessionHasNoErrors();

    expect(User::where('email', 'awa@exemple.test')->exists())->toBeTrue();

    $this->actingAs($admin)
        ->post(route('users.store'), newUserPayload(['name' => 'Doublon', 'email' => 'AWA@exemple.test']))
        ->assertSessionHasErrors('email');

    expect(User::where('name', 'Doublon')->exists())->toBeFalse();
});

test('les données invalides sont refusées', function () {
    $admin = User::factory()->admin()->create();

    foreach ([
        ['name' => ''],
        ['email' => 'pas-un-email'],
        ['role' => 'superadmin'],
        ['role' => ''],
        ['password' => 'court'],
        ['password' => ''],
    ] as $invalid) {
        $this->actingAs($admin)
            ->post(route('users.store'), newUserPayload($invalid))
            ->assertSessionHasErrors(array_key_first($invalid));
    }

    expect(User::count())->toBe(1);
});

test('la création est tracée sans aucun mot de passe', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('users.store'), newUserPayload());

    $log = AuditLog::firstOrFail();
    $user = User::where('email', 'awa@exemple.test')->firstOrFail();

    expect($log->action)->toBe('user.created')
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->entity_type)->toBe('user')
        ->and($log->entity_id)->toBe($user->id)
        ->and($log->details['snapshot']['email'])->toBe('awa@exemple.test')
        ->and($log->details['snapshot']['role'])->toBe('manager')
        ->and(array_key_exists('password', $log->details['snapshot']))->toBeFalse();

    expect(json_encode($log->details))
        ->not->toContain('Mdp-Temporaire-2026')
        ->not->toContain('$2y$');
});

test('le parcours complet : création, première connexion, changement obligatoire', function () {
    $admin = User::factory()->admin()->create();
    app(UserManager::class)->create($admin, 'Awa Traoré', 'awa@exemple.test', Role::Manager, 'Mdp-Temporaire-2026');

    $this->post(route('login'), ['email' => 'awa@exemple.test', 'password' => 'Mdp-Temporaire-2026'])
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertRedirect(route('profile.edit'));

    $this->put(route('password.update'), [
        'current_password' => 'Mdp-Temporaire-2026',
        'password' => 'Mon-Nouveau-Mdp-2026',
        'password_confirmation' => 'Mon-Nouveau-Mdp-2026',
    ])->assertSessionHasNoErrors();

    $this->get(route('dashboard'))->assertOk();
});

// ------------------------------------------------------------------ Modification

test('un administrateur modifie le nom, la messagerie et le rôle, et le changement de rôle est tracé', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Awa', 'email' => 'awa@exemple.test']);

    $this->actingAs($admin)
        ->put(route('users.update', $target), [
            'name' => 'Awa Traoré', 'email' => 'awa@exemple.test', 'role' => 'admin',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->role)->toBe(Role::Admin)
        ->and($target->fresh()->name)->toBe('Awa Traoré');

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe('user.role_changed')
        ->and($log->details['changes']['role'])->toEqual(['from' => 'manager', 'to' => 'admin'])
        ->and($log->details['changes']['name'])->toEqual(['from' => 'Awa', 'to' => 'Awa Traoré']);
});

test('une modification sans changement de rôle est tracée comme une simple modification', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Awa', 'email' => 'awa@exemple.test']);

    $this->actingAs($admin)->put(route('users.update', $target), [
        'name' => 'Awa Traoré', 'email' => 'awa@exemple.test', 'role' => 'manager',
    ]);

    expect(AuditLog::firstOrFail()->action)->toBe('user.updated');
});

test('une modification sans changement réel ne laisse aucune trace', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Awa', 'email' => 'awa@exemple.test']);

    $this->actingAs($admin)->put(route('users.update', $target), [
        'name' => 'Awa', 'email' => 'awa@exemple.test', 'role' => 'manager',
    ])->assertSessionHasNoErrors();

    expect(AuditLog::count())->toBe(0);
});

test("un compte peut conserver son adresse email à la modification, mais pas prendre celle d'un autre", function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['email' => 'autre@exemple.test']);
    $target = User::factory()->create(['email' => 'awa@exemple.test']);

    $this->actingAs($admin)->put(route('users.update', $target), [
        'name' => 'Awa', 'email' => 'awa@exemple.test', 'role' => 'manager',
    ])->assertSessionHasNoErrors();

    $this->actingAs($admin)->put(route('users.update', $target), [
        'name' => 'Awa', 'email' => $other->email, 'role' => 'manager',
    ])->assertSessionHasErrors('email');
});

test('un administrateur ne peut pas modifier son propre rôle', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create(); // un second administrateur existe

    $this->actingAs($admin)
        ->put(route('users.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'manager',
        ])
        ->assertSessionHasErrors('form');

    expect($admin->fresh()->role)->toBe(Role::Admin);

    // Mais il peut modifier son nom.
    $this->actingAs($admin)
        ->put(route('users.update', $admin), [
            'name' => 'Nouveau nom', 'email' => $admin->email, 'role' => 'admin',
        ])
        ->assertSessionHasNoErrors();

    expect($admin->fresh()->name)->toBe('Nouveau nom');
});

// ------------------------------------------------------------------ Activation

test('un administrateur désactive puis réactive un compte, et les deux actions sont tracées', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->patch(route('users.toggle', $target));
    expect($target->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch(route('users.toggle', $target));
    expect($target->fresh()->is_active)->toBeTrue();

    expect(AuditLog::orderBy('id')->pluck('action')->all())
        ->toBe(['user.deactivated', 'user.activated']);
});

test('un administrateur ne peut pas désactiver son propre compte', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();

    $this->actingAs($admin)->patch(route('users.toggle', $admin))->assertSessionHasErrors('form');

    expect($admin->fresh()->is_active)->toBeTrue();
    expect(AuditLog::count())->toBe(0);
});

test('un compte désactivé est déconnecté à sa prochaine requête', function () {
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->create();

    $this->actingAs($manager)->get(route('dashboard'))->assertOk();

    $this->actingAs($admin)->patch(route('users.toggle', $manager));

    $this->actingAs($manager->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
});

test('le dernier administrateur actif ne peut être ni désactivé ni rétrogradé', function () {
    $onlyAdmin = User::factory()->admin()->create();
    $outsider = User::factory()->create(); // acteur fictif : le service ne contrôle que les invariants
    $service = app(UserManager::class);

    expect(fn () => $service->toggle($outsider, $onlyAdmin))
        ->toThrow(UserManagementException::class, 'au moins un administrateur');

    expect(fn () => $service->update($outsider, $onlyAdmin, $onlyAdmin->name, $onlyAdmin->email, Role::Manager))
        ->toThrow(UserManagementException::class, 'au moins un administrateur');

    expect($onlyAdmin->fresh()->is_active)->toBeTrue()
        ->and($onlyAdmin->fresh()->role)->toBe(Role::Admin);

    // Avec un second administrateur actif, la désactivation devient possible...
    $second = User::factory()->admin()->create();
    $service->toggle($second, $onlyAdmin);
    expect($onlyAdmin->fresh()->is_active)->toBeFalse();

    // ...mais le dernier restant est de nouveau protégé.
    expect(fn () => $service->toggle($outsider, $second))
        ->toThrow(UserManagementException::class);
    expect($second->fresh()->is_active)->toBeTrue();
});

// ------------------------------------------------------------------ Réinitialisation du mot de passe

test('un administrateur réinitialise un mot de passe, de façon tracée et sans secret dans le journal', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['remember_token' => 'jeton-existant']);

    $this->actingAs($admin)
        ->put(route('users.password.update', $target), ['password' => 'Nouveau-Mdp-2026'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    $fresh = $target->fresh();

    expect(Hash::check('Nouveau-Mdp-2026', $fresh->password))->toBeTrue()
        ->and($fresh->must_change_password)->toBeTrue()
        ->and($fresh->remember_token)->toBeNull();

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe('user.password_reset')
        ->and($log->entity_id)->toBe($target->id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->details)->toBeNull();
});

test('un mot de passe trop faible est refusé à la réinitialisation', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->put(route('users.password.update', $target), ['password' => 'court'])
        ->assertSessionHasErrors('password');

    expect(Hash::check('password', $target->fresh()->password))->toBeTrue();
});

test('un administrateur ne réinitialise pas son propre mot de passe par cet écran', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('users.password.update', $admin), ['password' => 'Nouveau-Mdp-2026'])
        ->assertSessionHasErrors('form');

    expect(Hash::check('password', $admin->fresh()->password))->toBeTrue();
});

test('la réinitialisation ferme les sessions ouvertes du compte', function () {
    config(['session.driver' => 'database']);

    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    foreach ([['abc123', $target->id], ['def456', $admin->id]] as [$id, $userId]) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);
    }

    app(UserManager::class)->resetPassword($admin, $target, 'Nouveau-Mdp-2026');

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $admin->id)->count())->toBe(1);
});

// ------------------------------------------------------------------ Changement obligatoire

test('un compte à mot de passe temporaire ne peut accéder qu\'à son profil', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('profile.edit'));
    $this->actingAs($user)->get(route('products.index'))->assertRedirect(route('profile.edit'));
    $this->actingAs($user)->get(route('movements.index'))->assertRedirect(route('profile.edit'));

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Choisis un nouveau mot de passe');
});

test('un compte à mot de passe temporaire peut se déconnecter', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
});

test('le changement de mot de passe lève la restriction', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'Nouveau-Mdp-2026',
            'password_confirmation' => 'Nouveau-Mdp-2026',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->must_change_password)->toBeFalse();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
});

test("le nouveau mot de passe doit être différent de l'ancien", function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertSessionHasErrorsIn('updatePassword', 'password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

// ------------------------------------------------------------------ Liste

test('la liste des comptes se recherche et se filtre', function () {
    User::factory()->create(['name' => 'Awa Traoré', 'email' => 'awa@exemple.test']);
    User::factory()->create(['name' => 'Ibrahim Sawadogo', 'email' => 'ibrahim@exemple.test']);
    User::factory()->admin()->create(['name' => 'Mariam Ouédraogo', 'email' => 'mariam@exemple.test']);
    User::factory()->inactive()->create(['name' => 'Ancien Compte', 'email' => 'ancien@exemple.test']);
    $viewer = User::factory()->admin()->create(['name' => 'Visiteur Admin']);

    $this->actingAs($viewer)->get(route('users.index', ['search' => 'ibrahim']))
        ->assertSee('Ibrahim Sawadogo')->assertDontSee('Awa Traoré');

    $this->actingAs($viewer)->get(route('users.index', ['search' => 'awa@']))
        ->assertSee('Awa Traoré')->assertDontSee('Ibrahim Sawadogo');

    $this->actingAs($viewer)->get(route('users.index', ['role' => 'admin']))
        ->assertSee('Mariam Ouédraogo')->assertDontSee('Awa Traoré');

    $this->actingAs($viewer)->get(route('users.index', ['status' => 'inactive']))
        ->assertSee('Ancien Compte')->assertDontSee('Awa Traoré');

    $this->actingAs($viewer)->get(route('users.index', ['role' => 'inconnu']))
        ->assertSessionHasErrors('role');
});

test('la liste est paginée et l\'administrateur ne voit pas de bouton de désactivation sur son propre compte', function () {
    User::factory()->count(20)->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('users.index'))
        ->assertOk()
        ->assertViewHas('users', fn ($users) => $users->count() === 15 && $users->total() === 21)
        ->assertDontSee(route('users.toggle', $admin), false);
});

// ------------------------------------------------------------------ Audit

test("les actions sur les comptes apparaissent dans le journal d'audit", function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('users.store'), newUserPayload());

    $this->actingAs($admin)->get(route('audit.index'))
        ->assertOk()
        ->assertSee('Compte créé')
        ->assertSee('Utilisateur n° '.User::where('email', 'awa@exemple.test')->value('id'));
});

test('le journal ignore les attributs masqués du modèle', function () {
    $actor = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'Avant']);

    $user->name = 'Après';
    $user->password = 'Un-Autre-Mdp-2026';
    $user->remember_token = 'nouveau-jeton';

    app(AuditLogger::class)->updateWithAudit($user, $actor, App\Enums\AuditAction::UserUpdated);

    $changes = AuditLog::firstOrFail()->details['changes'];

    expect(array_keys($changes))->toBe(['name']);
    expect(json_encode($changes))->not->toContain('Un-Autre-Mdp-2026')->not->toContain('nouveau-jeton');
});