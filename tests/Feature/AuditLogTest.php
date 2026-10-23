<?php

use App\Enums\AuditAction;
use App\Enums\MovementReason;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;

function auditProductPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => 'ALI-001',
        'name' => 'Aliment démarrage',
        'category_id' => Category::factory()->create()->id,
        'unit' => 'sac',
        'purchase_price' => '18500.00',
        'minimum_stock' => 10,
    ], $overrides);
}

test("la création d'un produit est tracée avec son auteur", function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('products.store'), auditProductPayload());

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe('product.created')
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->entity_type)->toBe('product')
        ->and($log->entity_id)->toBe(Product::firstOrFail()->id)
        ->and($log->details['snapshot']['sku'])->toBe('ALI-001')
        ->and($log->details['snapshot']['name'])->toBe('Aliment démarrage');
});

test('une modification trace uniquement les champs réellement changés', function () {
    $product = Product::factory()->create([
        'sku' => 'ALI-001', 'name' => 'Ancien nom', 'unit' => 'sac',
        'purchase_price' => '18500.00', 'minimum_stock' => 10,
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('products.update', $product), [
            'sku' => 'ALI-001',
            'name' => 'Nouveau nom',
            'category_id' => $product->category_id,
            'unit' => 'sac',
            'purchase_price' => '18500.00',
            'minimum_stock' => 25,
        ])
        ->assertSessionHasNoErrors();

    $changes = AuditLog::firstOrFail()->details['changes'];

    expect(array_keys($changes))->toEqualCanonicalizing(['name', 'minimum_stock'])
    ->and($changes['name'])->toEqual(['from' => 'Ancien nom', 'to' => 'Nouveau nom'])
    ->and($changes['minimum_stock'])->toEqual(['from' => 10, 'to' => 25]);
});

test('une modification sans changement réel ne laisse aucune trace', function () {
    $product = Product::factory()->create([
        'sku' => 'ALI-001', 'name' => 'Même nom', 'unit' => 'sac',
        'purchase_price' => '18500.00', 'minimum_stock' => 10,
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('products.update', $product), [
            'sku' => 'ALI-001',
            'name' => 'Même nom',
            'category_id' => $product->category_id,
            'unit' => 'sac',
            'purchase_price' => '18500.00',
            'minimum_stock' => 10,
        ])
        ->assertSessionHasNoErrors();

    expect(AuditLog::count())->toBe(0);
});

test('la désactivation et la réactivation sont tracées', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();

    $this->actingAs($admin)->patch(route('products.toggle', $product));
    $this->actingAs($admin)->patch(route('products.toggle', $product));

    expect(AuditLog::orderBy('id')->pluck('action')->all())
        ->toBe(['product.deactivated', 'product.activated']);
});

test('les catégories et les fournisseurs sont tracés aussi', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('categories.store'), ['name' => 'Vaccins']);
    $category = Category::firstOrFail();
    $this->actingAs($admin)->put(route('categories.update', $category), ['name' => 'Vaccins et santé']);
    $this->actingAs($admin)->patch(route('categories.toggle', $category));

    $this->actingAs($admin)->post(route('suppliers.store'), ['name' => 'Agro Plus', 'lead_time_days' => 5]);
    $supplier = Supplier::firstOrFail();
    $this->actingAs($admin)->put(route('suppliers.update', $supplier), ['name' => 'Agro Plus SA', 'lead_time_days' => 5]);
    $this->actingAs($admin)->patch(route('suppliers.toggle', $supplier));

    expect(AuditLog::orderBy('id')->pluck('action')->all())->toBe([
        'category.created', 'category.updated', 'category.deactivated',
        'supplier.created', 'supplier.updated', 'supplier.deactivated',
    ]);
});

test('les ajustements et les corrections sont tracés, pas les entrées ni les sorties', function () {
    $user = User::factory()->admin()->create();
    $product = Product::factory()->create(['sku' => 'ALI-001', 'quantity' => 10]);
    $service = app(StockMovementService::class);

    $entry = $service->recordEntry($product, 5, $user);
    $service->recordExit($product, 2, $user, MovementReason::Sale);
    expect(AuditLog::count())->toBe(0);

    $adjustment = $service->recordAdjustment($product, 20, $user, 'Inventaire');
    $service->recordCorrection($entry, $user, 'Doublon');

    $logs = AuditLog::orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['stock.adjustment', 'stock.correction'])
        ->and($logs[0]->entity_type)->toBe('stock_movement')
        ->and($logs[0]->entity_id)->toBe($adjustment->id)
        ->and($logs[0]->details['product'])->toBe('ALI-001')
        ->and($logs[0]->details['quantity_before'])->toBe(13)
        ->and($logs[0]->details['quantity_after'])->toBe(20)
        ->and($logs[1]->details['corrects_movement_id'])->toBe($entry->id);
});

test("si l'écriture d'audit échoue, le mouvement n'est pas enregistré", function () {
    $product = Product::factory()->create(['quantity' => 10]);

    $this->mock(AuditLogger::class, fn ($mock) => $mock
        ->shouldReceive('record')
        ->andThrow(new RuntimeException('audit indisponible')));

    expect(fn () => app(StockMovementService::class)
        ->recordAdjustment($product, 7, User::factory()->create(), 'Inventaire'))
        ->toThrow(RuntimeException::class);

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::count())->toBe(0);
});

test('le journal est en lecture seule', function () {
    $log = app(AuditLogger::class)->record(null, AuditAction::DataExported, 'export');

    expect(fn () => $log->update(['action' => 'autre']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);

    expect($log->fresh()->action)->toBe('data.exported');
});

test("seul l'administrateur peut consulter le journal", function () {
    $this->get(route('audit.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route('audit.index'))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())->get(route('audit.index'))->assertOk();
});

test('le lien Audit n\'apparaît que pour un administrateur', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee(route('audit.index'), false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertSee(route('audit.index'), false);
});

test('le journal se filtre par action, utilisateur et période', function () {
    $alice = User::factory()->admin()->create(['name' => 'Alice Admin']);
    $bruno = User::factory()->admin()->create(['name' => 'Bruno Admin']);
    $logger = app(AuditLogger::class);

    $old = $logger->record($alice, AuditAction::ProductCreated, Product::factory()->create(['name' => 'Produit ancien']));
    DB::table('audit_logs')->where('id', $old->id)->update(['created_at' => now()->subDays(10)]);
    $logger->record($bruno, AuditAction::StockAdjustment, Product::factory()->create(['name' => 'Produit récent']));

    $viewer = User::factory()->admin()->create();

    $this->actingAs($viewer)->get(route('audit.index', ['action' => 'stock.adjustment']))
        ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->user_id === $bruno->id);

    $this->actingAs($viewer)->get(route('audit.index', ['user' => $alice->id]))
        ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->id === $old->id);

    $this->actingAs($viewer)->get(route('audit.index', ['from' => now()->subDays(3)->format('Y-m-d')]))
        ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->user_id === $bruno->id);

    $this->actingAs($viewer)->get(route('audit.index', ['to' => now()->subDays(5)->format('Y-m-d')]))
        ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->id === $old->id);
});

test('un filtre invalide est refusé', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('audit.index', ['action' => 'inconnue']))
        ->assertSessionHasErrors('action');
    $this->actingAs($admin)->get(route('audit.index', ['from' => 'pas-une-date']))
        ->assertSessionHasErrors('from');
});

test('le contenu des détails est échappé à l\'affichage', function () {
    $admin = User::factory()->admin()->create();
    app(AuditLogger::class)->record($admin, AuditAction::ProductCreated, Product::factory()->create(), [
        'snapshot' => ['name' => '<script>alert(1)</script>'],
    ]);

    $this->actingAs($admin)->get(route('audit.index'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('les détails sont mis en forme de façon lisible', function () {
    $log = new AuditLog(['details' => [
        'changes' => [
            'name' => ['from' => 'A', 'to' => 'B'],
            'is_active' => ['from' => true, 'to' => false],
        ],
        'reason' => 'Test',
        'note' => null,
    ]]);

    expect($log->detailLines())->toBe([
        'name : A → B',
        'is_active : oui → non',
        'reason : Test',
        'note : —',
    ]);
});

test('le journal est paginé', function () {
    $admin = User::factory()->admin()->create();
    $logger = app(AuditLogger::class);
    $product = Product::factory()->create();

    foreach (range(1, 30) as $i) {
        $logger->record($admin, AuditAction::ProductUpdated, $product, ['changes' => ['name' => ['from' => 'a', 'to' => 'b']]]);
    }

    $this->actingAs($admin)->get(route('audit.index'))
        ->assertViewHas('logs', fn ($logs) => $logs->count() === 25 && $logs->total() === 30);
});