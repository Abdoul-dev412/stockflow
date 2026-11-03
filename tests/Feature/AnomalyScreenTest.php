<?php

use App\Enums\AnomalyRule;
use App\Enums\AnomalyStatus;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

function makeAnomaly(array $overrides = []): Anomaly
{
    $product = $overrides['product'] ?? Product::factory()->create();
    unset($overrides['product']);

    return Anomaly::create($overrides + [
        'rule_code' => AnomalyRule::Spike,
        'product_id' => $product->id,
        'status' => AnomalyStatus::New,
        'message' => 'Sortie exceptionnelle de test',
        'dedupe_key' => 'test:'.Str::uuid(),
        'occurred_at' => now(),
        'detected_at' => now(),
    ]);
}

test("seul l'administrateur voit et traite les anomalies", function () {
    $anomaly = makeAnomaly();

    $this->get(route('anomalies.index'))->assertRedirect(route('login'));
    $this->patch(route('anomalies.update', $anomaly), ['status' => 'reviewed'])->assertRedirect(route('login'));

    $manager = User::factory()->create();
    $this->actingAs($manager)->get(route('anomalies.index'))->assertForbidden();
    $this->actingAs($manager)->patch(route('anomalies.update', $anomaly), ['status' => 'reviewed'])->assertForbidden();

    expect($anomaly->fresh()->status)->toBe(AnomalyStatus::New);

    $this->actingAs(User::factory()->admin()->create())->get(route('anomalies.index'))->assertOk();
});

test("le lien Anomalies n'apparaît que pour un administrateur", function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertDontSee(route('anomalies.index'), false);

    $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))
        ->assertSee(route('anomalies.index'), false);
});

test('la liste montre par défaut les anomalies à examiner', function () {
    makeAnomaly(['message' => 'Anomalie nouvelle']);
    makeAnomaly(['message' => 'Anomalie examinée', 'status' => AnomalyStatus::Reviewed]);
    makeAnomaly(['message' => 'Anomalie ignorée', 'status' => AnomalyStatus::Ignored]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('anomalies.index'))
        ->assertSee('Anomalie nouvelle')->assertDontSee('Anomalie examinée')->assertDontSee('Anomalie ignorée')
        ->assertSee('1 à examiner');

    $this->actingAs($admin)->get(route('anomalies.index', ['status' => '']))
        ->assertSee('Anomalie nouvelle')->assertSee('Anomalie examinée')->assertSee('Anomalie ignorée');

    $this->actingAs($admin)->get(route('anomalies.index', ['status' => 'ignored']))
        ->assertSee('Anomalie ignorée')->assertDontSee('Anomalie nouvelle');
});

test('la liste se filtre par règle et valide ses filtres', function () {
    makeAnomaly(['message' => 'Sortie exceptionnelle A', 'rule_code' => AnomalyRule::Spike]);
    makeAnomaly(['message' => 'Pertes anormales B', 'rule_code' => AnomalyRule::LossShare]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('anomalies.index', ['rule' => 'R3']))
        ->assertSee('Pertes anormales B')->assertDontSee('Sortie exceptionnelle A');

    $this->actingAs($admin)->get(route('anomalies.index', ['rule' => 'R9']))->assertSessionHasErrors('rule');
    $this->actingAs($admin)->get(route('anomalies.index', ['status' => 'inconnu']))->assertSessionHasErrors('status');
});

test('une anomalie peut être marquée comme examinée, avec ou sans commentaire', function () {
    $admin = User::factory()->admin()->create();
    $anomaly = makeAnomaly();

    $this->actingAs($admin)
        ->patch(route('anomalies.update', $anomaly), ['status' => 'reviewed', 'comment' => ''])
        ->assertSessionHasNoErrors();

    $fresh = $anomaly->fresh();

    expect($fresh->status)->toBe(AnomalyStatus::Reviewed)
        ->and($fresh->comment)->toBeNull()
        ->and($fresh->reviewed_by)->toBe($admin->id)
        ->and($fresh->reviewed_at)->not->toBeNull();

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe('anomaly.reviewed')
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->entity_type)->toBe('anomaly')
        ->and($log->entity_id)->toBe($anomaly->id)
        ->and($log->details['rule'])->toBe('R1');
});

test('ignorer une anomalie exige un commentaire', function () {
    $admin = User::factory()->admin()->create();
    $anomaly = makeAnomaly();

    foreach (['', '   ', null] as $blank) {
        $this->actingAs($admin)
            ->patch(route('anomalies.update', $anomaly), ['status' => 'ignored', 'comment' => $blank])
            ->assertSessionHasErrors('comment');
    }

    expect($anomaly->fresh()->status)->toBe(AnomalyStatus::New)->and(AuditLog::count())->toBe(0);

    $this->actingAs($admin)
        ->patch(route('anomalies.update', $anomaly), ['status' => 'ignored', 'comment' => 'Inventaire annuel, écart attendu'])
        ->assertSessionHasNoErrors();

    $fresh = $anomaly->fresh();

    expect($fresh->status)->toBe(AnomalyStatus::Ignored)
        ->and($fresh->comment)->toBe('Inventaire annuel, écart attendu');

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe('anomaly.ignored')
        ->and($log->details['comment'])->toBe('Inventaire annuel, écart attendu');
});

test('un statut invalide ou un commentaire trop long est refusé', function () {
    $admin = User::factory()->admin()->create();
    $anomaly = makeAnomaly();

    foreach (['new', 'autre', '', null] as $invalid) {
        $this->actingAs($admin)
            ->patch(route('anomalies.update', $anomaly), ['status' => $invalid])
            ->assertSessionHasErrors('status');
    }

    $this->actingAs($admin)
        ->patch(route('anomalies.update', $anomaly), ['status' => 'reviewed', 'comment' => str_repeat('a', 501)])
        ->assertSessionHasErrors('comment');

    expect($anomaly->fresh()->status)->toBe(AnomalyStatus::New);
});

test('une anomalie déjà traitée ne peut pas être retraitée', function () {
    $admin = User::factory()->admin()->create();
    $anomaly = makeAnomaly();

    $this->actingAs($admin)->patch(route('anomalies.update', $anomaly), ['status' => 'reviewed']);

    $this->actingAs($admin)
        ->patch(route('anomalies.update', $anomaly), ['status' => 'ignored', 'comment' => 'Trop tard'])
        ->assertSessionHasErrors('form');

    expect($anomaly->fresh()->status)->toBe(AnomalyStatus::Reviewed)->and(AuditLog::count())->toBe(1);
});

test('les formulaires de traitement ne sont proposés que pour les anomalies nouvelles', function () {
    $new = makeAnomaly(['message' => 'Nouvelle']);
    $done = makeAnomaly(['message' => 'Traitée', 'status' => AnomalyStatus::Reviewed, 'reviewed_at' => now()]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('anomalies.index', ['status' => '']))
        ->assertSee(route('anomalies.update', $new), false)
        ->assertDontSee(route('anomalies.update', $done), false);
});

test("le contenu d'une anomalie est échappé à l'affichage", function () {
    makeAnomaly(['message' => '<script>alert(1)</script>']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('anomalies.index'))
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('la liste est paginée', function () {
    foreach (range(1, 25) as $i) {
        makeAnomaly();
    }

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('anomalies.index'))
        ->assertViewHas('anomalies', fn ($anomalies) => $anomalies->count() === 20 && $anomalies->total() === 25);
});

test("le traitement d'une anomalie apparaît dans le journal d'audit", function () {
    $admin = User::factory()->admin()->create();
    $anomaly = makeAnomaly();

    $this->actingAs($admin)->patch(route('anomalies.update', $anomaly), ['status' => 'reviewed']);

    $this->actingAs($admin)->get(route('audit.index'))
        ->assertOk()
        ->assertSee('Anomalie examinée');
});