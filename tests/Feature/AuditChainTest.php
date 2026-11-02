<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\IntegrityCheck;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\AuditLogger;
use App\Services\IntegrityVerifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function chainLogger(): AuditLogger
{
    return app(AuditLogger::class);
}

function chainVerifier(): IntegrityVerifier
{
    return app(IntegrityVerifier::class);
}

/** @return array<int, AuditLog> */
function recordThreeEntries(): array
{
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();

    return [
        chainLogger()->record($admin, AuditAction::ProductCreated, $product, ['snapshot' => ['name' => 'Aliment démarrage']]),
        chainLogger()->record($admin, AuditAction::ProductUpdated, $product, ['changes' => ['name' => ['from' => 'A', 'to' => 'B']]]),
        chainLogger()->record($admin, AuditAction::DataExported, 'export', ['export' => 'products']),
    ];
}

function issueChecks($report): array
{
    return array_column($report->issues, 'check');
}

// ------------------------------------------------------------------ Construction de la chaîne

test("la première entrée se chaîne sur l'empreinte d'origine, les suivantes sur la précédente", function () {
    [$a, $b, $c] = recordThreeEntries();

    expect($a->previous_hash)->toBe(AuditChain::GENESIS)
        ->and($a->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($b->previous_hash)->toBe($a->hash)
        ->and($c->previous_hash)->toBe($b->hash)
        ->and(count(array_unique([$a->hash, $b->hash, $c->hash])))->toBe(3);

    $head = DB::table('audit_chain_head')->where('id', 1)->first();

    expect((int) $head->last_id)->toBe($c->id)->and($head->last_hash)->toBe($c->hash);
});

test('une chaîne intacte est vérifiée sans anomalie et fournit une ancre', function () {
    [, , $c] = recordThreeEntries();

    $report = chainVerifier()->verifyAuditChain();

    expect($report->ok())->toBeTrue()
        ->and($report->scanned['audit_rows'])->toBe(3)
        ->and($report->meta['anchor'])->toBe("{$c->id}:{$c->hash}");
});

test('un journal vide est valide', function () {
    $report = chainVerifier()->verifyAuditChain();

    expect($report->ok())->toBeTrue()
        ->and($report->scanned['audit_rows'])->toBe(0)
        ->and($report->meta['anchor'])->toBeNull();
});

test('la chaîne reste valide après des opérations réelles', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('categories.store'), ['name' => 'Vaccins']);
    $category = App\Models\Category::firstOrFail();
    $this->actingAs($admin)->put(route('categories.update', $category), ['name' => 'Vaccins et santé']);
    $this->actingAs($admin)->patch(route('categories.toggle', $category));
    $this->actingAs($admin)->get(route('exports.products'));

    expect(AuditLog::count())->toBe(4);
    expect(chainVerifier()->verifyAuditChain()->ok())->toBeTrue();
});

test("une écriture annulée ne laisse aucune trace dans la chaîne", function () {
    [, , $c] = recordThreeEntries();
    $admin = User::first();

    try {
        DB::transaction(function () use ($admin) {
            chainLogger()->record($admin, AuditAction::DataExported, 'export', ['export' => 'annulé']);

            throw new RuntimeException('annulé');
        });
    } catch (RuntimeException) {
    }

    $head = DB::table('audit_chain_head')->where('id', 1)->first();

    expect(AuditLog::count())->toBe(3)
        ->and((int) $head->last_id)->toBe($c->id)
        ->and(chainVerifier()->verifyAuditChain()->ok())->toBeTrue();
});

// ------------------------------------------------------------------ Contenu canonique

test("l'empreinte ne dépend pas de l'ordre des clés, mais dépend du contenu", function () {
    $a = [
        'v' => 1, 'user_id' => 1, 'action' => 'x', 'entity_type' => 'product', 'entity_id' => 2,
        'details' => ['b' => 1, 'a' => ['z' => 1, 'y' => [3, 2, 1]]],
        'created_at' => '2026-01-01 10:00:00',
    ];
    $b = [
        'created_at' => '2026-01-01 10:00:00',
        'details' => ['a' => ['y' => [3, 2, 1], 'z' => 1], 'b' => 1],
        'entity_id' => 2, 'entity_type' => 'product', 'action' => 'x', 'user_id' => 1, 'v' => 1,
    ];

    $reference = AuditChain::hashFor($a, AuditChain::GENESIS);

    expect(AuditChain::hashFor($b, AuditChain::GENESIS))->toBe($reference);

    $reordered = $a;
    $reordered['details']['a']['y'] = [1, 2, 3];   // l'ordre d'une liste compte
    expect(AuditChain::hashFor($reordered, AuditChain::GENESIS))->not->toBe($reference);

    $other = $a;
    $other['action'] = 'y';
    expect(AuditChain::hashFor($other, AuditChain::GENESIS))->not->toBe($reference);

    expect(AuditChain::hashFor($a, str_repeat('a', 64)))->not->toBe($reference);
});

test('la vérification résiste au réordonnancement des clés JSON opéré par MySQL 8', function () {
    $admin = User::factory()->admin()->create();
    $log = chainLogger()->record($admin, AuditAction::ProductCreated, Product::factory()->create(), [
        'snapshot' => ['name' => 'X', 'is_active' => true, 'lead_time_days' => 5],
    ]);

    DB::table('audit_logs')->where('id', $log->id)->update([
        'details' => json_encode(['snapshot' => ['lead_time_days' => 5, 'is_active' => true, 'name' => 'X']]),
    ]);

    expect(chainVerifier()->verifyAuditChain()->ok())->toBeTrue();
});

test('des détails variés (énumération, date, accents, imbrication, booléens, nul) survivent à la relecture', function () {
    $admin = User::factory()->admin()->create();

    chainLogger()->record($admin, AuditAction::UserUpdated, $admin, [
        'changes' => [
            'role' => ['from' => Role::Manager, 'to' => Role::Admin],
            'email_verified_at' => ['from' => null, 'to' => Carbon::parse('2026-01-02 03:04:05')],
            'name' => ['from' => 'Aliment démarrage / été', 'to' => "L'été \"chaud\""],
            'is_active' => ['from' => true, 'to' => false],
            'price' => ['from' => '18500.00', 'to' => '1234.50'],
            'nested' => ['b' => [1, 2], 'a' => ['y' => 1, 'x' => 0]],
        ],
    ]);

    expect(chainVerifier()->verifyAuditChain()->ok())->toBeTrue();
});

// ------------------------------------------------------------------ Détection des altérations

test("une ligne modifiée est détectée, sans fausse alerte sur les suivantes", function () {
    [, $b] = recordThreeEntries();

    DB::table('audit_logs')->where('id', $b->id)->update(['action' => 'product.deactivated']);

    $report = chainVerifier()->verifyAuditChain();

    expect($report->count())->toBe(1)
        ->and($report->issues[0]['check'])->toBe('hash_mismatch')
        ->and($report->issues[0]['ref'])->toBe("audit:{$b->id}");
});

test('des détails modifiés sont détectés', function () {
    [$a] = recordThreeEntries();

    DB::table('audit_logs')->where('id', $a->id)->update([
        'details' => json_encode(['snapshot' => ['name' => 'Piraté']]),
    ]);

    expect(issueChecks(chainVerifier()->verifyAuditChain()))->toBe(['hash_mismatch']);
});

test('un auteur ou une date modifiés sont détectés', function () {
    [$a, $b] = recordThreeEntries();
    $other = User::factory()->create();

    DB::table('audit_logs')->where('id', $a->id)->update(['user_id' => $other->id]);
    DB::table('audit_logs')->where('id', $b->id)->update(['created_at' => now()->subYear()]);

    $report = chainVerifier()->verifyAuditChain();

    expect(issueChecks($report))->toBe(['hash_mismatch', 'hash_mismatch'])
        ->and(array_column($report->issues, 'ref'))->toBe(["audit:{$a->id}", "audit:{$b->id}"]);
});

test('une ligne supprimée au milieu rompt le chaînage', function () {
    [, $b, $c] = recordThreeEntries();

    DB::table('audit_logs')->where('id', $b->id)->delete();

    $report = chainVerifier()->verifyAuditChain();

    expect($report->count())->toBe(1)
        ->and($report->issues[0]['check'])->toBe('chain_break')
        ->and($report->issues[0]['ref'])->toBe("audit:{$c->id}");
});

test('la suppression de la première ligne est détectée', function () {
    [$a, $b] = recordThreeEntries();

    DB::table('audit_logs')->where('id', $a->id)->delete();

    $report = chainVerifier()->verifyAuditChain();

    expect($report->issues[0]['check'])->toBe('chain_break')
        ->and($report->issues[0]['ref'])->toBe("audit:{$b->id}");
});

test('la suppression des dernières lignes est détectée par la tête de chaîne', function () {
    [, , $c] = recordThreeEntries();

    DB::table('audit_logs')->where('id', $c->id)->delete();

    expect(issueChecks(chainVerifier()->verifyAuditChain()))->toBe(['head_mismatch']);
});

test('la suppression de tout le journal est détectée', function () {
    recordThreeEntries();

    DB::table('audit_logs')->delete();

    $report = chainVerifier()->verifyAuditChain();

    expect(issueChecks($report))->toBe(['head_mismatch'])
        ->and($report->scanned['audit_rows'])->toBe(0);
});

test('une ligne forgée ajoutée à la main est détectée', function () {
    recordThreeEntries();

    DB::table('audit_logs')->insert([
        'user_id' => null,
        'action' => 'product.created',
        'entity_type' => 'product',
        'entity_id' => 999,
        'details' => null,
        'previous_hash' => str_repeat('a', 64),
        'hash' => str_repeat('b', 64),
        'created_at' => now(),
    ]);

    $checks = issueChecks(chainVerifier()->verifyAuditChain());

    expect($checks)->toContain('chain_break')->toContain('hash_mismatch')->toContain('head_mismatch');
});

test('une ligne dont l\'empreinte a été effacée est signalée', function () {
    [, $b, $c] = recordThreeEntries();

    DB::table('audit_logs')->where('id', $b->id)->update(['hash' => null]);

    $report = chainVerifier()->verifyAuditChain();

    expect(issueChecks($report))->toBe(['missing_hash', 'chain_break'])
        ->and(array_column($report->issues, 'ref'))->toBe(["audit:{$b->id}", "audit:{$c->id}"]);
});

// ------------------------------------------------------------------ Ancre externe

test("l'ancre conservée hors de la base permet de détecter une chaîne entièrement recalculée", function () {
    [$a, $b, $c] = recordThreeEntries();
    $anchor = chainVerifier()->verifyAuditChain()->meta['anchor'];

    expect(chainVerifier()->verifyAuditChain($anchor)->ok())->toBeTrue();

    // Un attaquant modifie la première ligne, puis recalcule TOUTE la chaîne et la tête.
    DB::table('audit_logs')->where('id', $a->id)->update(['action' => 'product.deactivated']);
    DB::table('audit_logs')->update(['previous_hash' => null, 'hash' => null]);
    app(AuditChain::class)->backfill();

    // La chaîne est de nouveau cohérente en elle-même...
    expect(chainVerifier()->verifyAuditChain()->ok())->toBeTrue();

    // ... mais l'ancre extérieure ne correspond plus.
    $report = chainVerifier()->verifyAuditChain($anchor);

    expect(issueChecks($report))->toBe(['anchor_mismatch']);
});

test("une ancre pointant une entrée supprimée est signalée", function () {
    [, , $c] = recordThreeEntries();
    $anchor = "{$c->id}:{$c->hash}";

    DB::table('audit_logs')->where('id', $c->id)->delete();

    expect(issueChecks(chainVerifier()->verifyAuditChain($anchor)))->toContain('anchor_mismatch');
});

// ------------------------------------------------------------------ Rétro-remplissage

test('les entrées antérieures à la chaîne sont chaînées par le rétro-remplissage', function () {
    $admin = User::factory()->admin()->create();

    foreach ([1, 2, 3] as $i) {
        DB::table('audit_logs')->insert([
            'user_id' => $admin->id,
            'action' => 'product.updated',
            'entity_type' => 'product',
            'entity_id' => $i,
            'details' => json_encode(['changes' => ['name' => ['from' => "A{$i}", 'to' => "B{$i}"]]]),
            'created_at' => now()->subDays(10 - $i),
        ]);
    }

    DB::table('audit_chain_head')->delete();

    expect(app(AuditChain::class)->backfill())->toBe(3);
    expect(chainVerifier()->verifyAuditChain()->ok())->toBeTrue();

    // La suite de la chaîne se greffe sur la dernière entrée rétro-chaînée.
    $next = chainLogger()->record($admin, AuditAction::DataExported, 'export');

    expect($next->previous_hash)->toBe(AuditLog::orderBy('id')->skip(2)->first()->hash);
    expect(chainVerifier()->verifyAuditChain()->ok())->toBeTrue();
});

// ------------------------------------------------------------------ Commande audit:verify

test('audit:verify réussit sur une chaîne intacte et affiche l\'ancre', function () {
    [, , $c] = recordThreeEntries();

    $this->artisan('audit:verify')
        ->expectsOutputToContain("Journal d'audit intact : 3 entrée(s)")
        ->expectsOutputToContain("{$c->id}:{$c->hash}")
        ->assertExitCode(0);

    expect(IntegrityCheck::lastOf('audit')->status)->toBe('ok');
});

test('audit:verify échoue et décrit les anomalies', function () {
    [, $b] = recordThreeEntries();
    DB::table('audit_logs')->where('id', $b->id)->update(['action' => 'product.deactivated']);

    $this->artisan('audit:verify')
        ->expectsOutputToContain("Entrée d'audit n° {$b->id}")
        ->assertExitCode(1);

    $check = IntegrityCheck::lastOf('audit');

    expect($check->status)->toBe('failed')->and($check->issues_count)->toBe(1);
});

test('audit:verify accepte une ancre valide et refuse une ancre mal formée', function () {
    [, , $c] = recordThreeEntries();

    $this->artisan('audit:verify', ['--anchor' => "{$c->id}:{$c->hash}"])->assertExitCode(0);
    $this->artisan('audit:verify', ['--anchor' => "{$c->id}:".str_repeat('f', 64)])->assertExitCode(1);
    $this->artisan('audit:verify', ['--anchor' => 'n-importe-quoi'])->assertExitCode(2);
});

test("une altération du journal est tracée une seule fois dans l'audit lui-même", function () {
    [, $b] = recordThreeEntries();
    DB::table('audit_logs')->where('id', $b->id)->update(['action' => 'product.deactivated']);

    $this->artisan('audit:verify')->assertExitCode(1);
    $this->artisan('audit:verify')->assertExitCode(1);

    expect(IntegrityCheck::where('type', 'audit')->count())->toBe(2)
        ->and(AuditLog::where('action', 'integrity.failed')->count())->toBe(1);
});

test("la commande de premier administrateur s'inscrit proprement dans la chaîne", function () {
    $this->artisan('stockflow:create-admin')
        ->expectsQuestion('Nom complet', 'Awa Traoré')
        ->expectsQuestion('Adresse email', 'awa@exemple.test')
        ->expectsQuestion('Mot de passe (non affiché)', 'Mdp-Solide-2026')
        ->expectsQuestion('Confirme le mot de passe', 'Mdp-Solide-2026')
        ->assertExitCode(0);

    expect(AuditLog::count())->toBe(1)
        ->and(AuditLog::first()->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and(chainVerifier()->verifyAuditChain()->ok())->toBeTrue();
});