<?php

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;

/** Enregistre un appareil par l'écran et retourne [appareil, jeton en clair]. */
function registerDeviceFor(User $user, string $name = 'Tablette magasin'): array
{
    $token = null;

    test()->actingAs($user)
        ->post(route('devices.store'), ['name' => $name])
        ->assertSessionHas('device_token', function (array $issued) use (&$token) {
            $token = $issued['token'];

            return true;
        });

    // Consomme le message flash après avoir récupéré le jeton.
    test()->actingAs($user)->get(route('devices.index'))->assertOk();

    return [Device::latest('id')->firstOrFail(), $token];
}

test('un visiteur ne peut pas gérer les appareils', function () {
    $this->get(route('devices.index'))->assertRedirect(route('login'));
    $this->post(route('devices.store'), ['name' => 'Tablette'])->assertRedirect(route('login'));
});

test('un utilisateur enregistre un appareil et reçoit son jeton une seule fois', function () {
    $user = User::factory()->create();

    [$device, $token] = registerDeviceFor($user);

    expect($token)->toStartWith('sf_')->and(strlen($token))->toBe(43)
        ->and($device->token_hash)->toBe(hash('sha256', $token))
        ->and($device->user_id)->toBe($user->id)
        ->and($device->name)->toBe('Tablette magasin')
        ->and($device->token_expires_at->isFuture())->toBeTrue()
        ->and($device->token_expires_at->lessThanOrEqualTo(now()->addDays(7)->addMinute()))->toBeTrue()
        ->and($device->toArray())->not->toHaveKey('token_hash');

    // Le jeton n'apparaît que sur la page qui suit l'enregistrement.
    $this->actingAs($user)->get(route('devices.index'))
        ->assertOk()
        ->assertSee('Tablette magasin')
        ->assertDontSee('Jeton à copier maintenant');
});

test("la page qui suit l'enregistrement affiche le jeton à copier", function () {
    $this->actingAs(User::factory()->create())
        ->followingRedirects()
        ->post(route('devices.store'), ['name' => 'Smartphone'])
        ->assertOk()
        ->assertSee('Jeton à copier maintenant')
        ->assertSee('sf_');
});

test("l'enregistrement est tracé sans jamais écrire le jeton", function () {
    $user = User::factory()->create();

    [$device, $token] = registerDeviceFor($user);

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe('device.registered')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->entity_type)->toBe('device')
        ->and($log->entity_id)->toBe($device->id)
        ->and($log->details)->toBe(['name' => 'Tablette magasin']);

    expect(json_encode($log->details))->not->toContain($token)->not->toContain($device->token_hash);
});

test('l\'enregistrement peut aussi se faire en JSON, pour la future application hors ligne', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('devices.store'), ['name' => 'PWA'])
        ->assertCreated()
        ->assertJsonStructure(['device' => ['id', 'name', 'expires_at'], 'token']);

    $this->withToken($response->json('token'))->getJson('/sync/changes')->assertOk();
});

test("le nom de l'appareil est obligatoire et limité à 100 caractères", function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('devices.store'), ['name' => ''])->assertSessionHasErrors('name');
    $this->actingAs($user)->post(route('devices.store'), ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name');

    expect(Device::count())->toBe(0);
});

test('le nombre d\'appareils actifs par compte est limité, les appareils révoqués ne comptent pas', function () {
    config(['stockflow.sync.max_devices_per_user' => 2]);
    $user = User::factory()->create();

    [$first] = registerDeviceFor($user, 'A');
    registerDeviceFor($user, 'B');

    $this->actingAs($user)->post(route('devices.store'), ['name' => 'C'])->assertSessionHasErrors('form');
    $this->actingAs($user)->postJson(route('devices.store'), ['name' => 'C'])->assertStatus(422);
    expect(Device::count())->toBe(2);

    $this->actingAs($user)->patch(route('devices.revoke', $first));
    $this->actingAs($user)->post(route('devices.store'), ['name' => 'C'])->assertSessionHasNoErrors();

    expect(Device::count())->toBe(3);
});

test("le renouvellement remplace le jeton : l'ancien cesse de fonctionner", function () {
    $user = User::factory()->create();
    [$device, $oldToken] = registerDeviceFor($user);
    $device->forceFill(['token_expires_at' => now()->addDay()])->save();

    $newToken = null;
    $this->actingAs($user)
        ->post(route('devices.renew', $device))
        ->assertSessionHas('device_token', function (array $issued) use (&$newToken) {
            $newToken = $issued['token'];

            return true;
        });

    expect($newToken)->not->toBe($oldToken);

    $this->withToken($oldToken)->getJson('/sync/changes')->assertUnauthorized();
    $this->withToken($newToken)->getJson('/sync/changes')->assertOk();

    expect($device->fresh()->token_expires_at->greaterThan(now()->addDays(6)))->toBeTrue();
    expect(AuditLog::where('action', 'device.token_renewed')->count())->toBe(1);
});

test("seul le propriétaire peut renouveler le jeton d'un appareil, pas même un administrateur", function () {
    $owner = User::factory()->create();
    [$device] = registerDeviceFor($owner);

    $this->actingAs(User::factory()->create())->post(route('devices.renew', $device))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->post(route('devices.renew', $device))->assertForbidden();
});

test('un appareil révoqué ne peut plus être renouvelé', function () {
    $owner = User::factory()->create();
    [$device] = registerDeviceFor($owner);
    $device->forceFill(['revoked_at' => now()])->save();

    $this->actingAs($owner)->post(route('devices.renew', $device))->assertForbidden();
});

test('le propriétaire et un administrateur peuvent révoquer, les autres non', function () {
    $owner = User::factory()->create();
    [$mine] = registerDeviceFor($owner, 'Mine');
    [$other] = registerDeviceFor(User::factory()->create(), 'Autre');

    $this->actingAs($owner)->patch(route('devices.revoke', $mine))->assertSessionHasNoErrors();
    expect($mine->fresh()->isRevoked())->toBeTrue();

    $this->actingAs($owner)->patch(route('devices.revoke', $other))->assertForbidden();
    expect($other->fresh()->isRevoked())->toBeFalse();

    $this->actingAs(User::factory()->admin()->create())->patch(route('devices.revoke', $other))->assertSessionHasNoErrors();
    expect($other->fresh()->isRevoked())->toBeTrue();

    expect(AuditLog::where('action', 'device.revoked')->count())->toBe(2);
});

test('un appareil révoqué ne peut plus synchroniser et ne peut pas être révoqué deux fois', function () {
    $owner = User::factory()->create();
    [$device, $token] = registerDeviceFor($owner);

    $this->actingAs($owner)->patch(route('devices.revoke', $device));

    $this->withToken($token)->getJson('/sync/changes')->assertForbidden()->assertJsonPath('error', 'device_revoked');
    $this->actingAs($owner)->patch(route('devices.revoke', $device))->assertForbidden();
});

test("un utilisateur voit ses appareils, l'administrateur voit tous les appareils avec leur propriétaire", function () {
    $alice = User::factory()->create(['name' => 'Alice Gestionnaire']);
    $bruno = User::factory()->create(['name' => 'Bruno Gestionnaire']);
    registerDeviceFor($alice, 'Tablette Alice');
    registerDeviceFor($bruno, 'Tablette Bruno');

    $this->actingAs($alice)->get(route('devices.index'))
        ->assertSee('Tablette Alice')->assertDontSee('Tablette Bruno');

    $this->actingAs(User::factory()->admin()->create())->get(route('devices.index'))
        ->assertSee('Tablette Alice')->assertSee('Tablette Bruno')
        ->assertSee('Alice Gestionnaire')->assertSee('Bruno Gestionnaire');
});

test('le lien Appareils est présent dans la navigation', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertSee(route('devices.index'), false);
});