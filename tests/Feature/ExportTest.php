<?php

use App\Enums\MovementReason;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockMovementService;
use App\Support\CsvExport;

/** @return array<int, array<int, string|null>> */
function parseCsvExport(string $content): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $lines = array_values(array_filter(preg_split('/\R/', $content), fn ($line) => $line !== ''));

    return array_map(fn ($line) => str_getcsv($line, ';', '"', ''), $lines);
}

function exportRows($response): array
{
    return parseCsvExport($response->streamedContent());
}

// ----------------------------------------------------------------- Accès

test('seul un administrateur peut exporter', function () {
    $routes = ['exports.products', 'exports.stock-levels', 'exports.movements'];

    // 1. Visiteur non connecté
    foreach ($routes as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    // 2. Gestionnaire
    $manager = User::factory()->create();
    foreach ($routes as $route) {
        $this->actingAs($manager)->get(route($route))->assertForbidden();
    }

    // 3. Administrateur
    $admin = User::factory()->admin()->create();
    foreach ($routes as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk();
    }
});

test('un export refusé ne laisse aucune trace', function () {
    $this->actingAs(User::factory()->create())->get(route('exports.products'))->assertForbidden();

    expect(AuditLog::count())->toBe(0);
});

test("les boutons d'export n'apparaissent que pour un administrateur", function () {
    $this->actingAs(User::factory()->create())
        ->get(route('products.index'))
        ->assertDontSee(route('exports.products'), false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('products.index'))
        ->assertSee(route('exports.products'), false)
        ->assertSee(route('exports.stock-levels'), false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('movements.index'))
        ->assertSee(route('exports.movements'), false);
});

// ----------------------------------------------------------------- Produits

test('l\'export des produits est un fichier CSV bien formé', function () {
    $category = Category::factory()->create(['name' => 'Aliments']);
    $supplier = Supplier::factory()->create(['name' => 'Agro Plus']);
    Product::factory()->create([
        'sku' => 'ALI-001', 'barcode' => null, 'name' => 'Aliment démarrage',
        'category_id' => $category->id, 'supplier_id' => $supplier->id, 'unit' => 'sac',
        'purchase_price' => '1234.5', 'sale_price' => null, 'quantity' => 5, 'minimum_stock' => 10,
    ]);

    $response = $this->actingAs(User::factory()->admin()->create())->get(route('exports.products'));

    $response->assertOk()->assertDownload();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->headers->get('content-disposition'))->toContain('produits-');

    $content = $response->streamedContent();
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue();

    $rows = parseCsvExport($content);

    expect($rows)->toHaveCount(2)
        ->and($rows[0][0])->toBe('SKU')
        ->and(array_slice($rows[1], 0, 12))->toBe([
            'ALI-001', '', 'Aliment démarrage', 'Aliments', 'Agro Plus', 'sac',
            '1234,50', '', '5', '10', 'Stock faible', 'Actif',
        ]);
});

test("l'export des produits respecte les filtres de la liste", function () {
    $category = Category::factory()->create();
    Product::factory()->create(['sku' => 'NOR-001', 'name' => 'Normal', 'quantity' => 50, 'minimum_stock' => 10]);
    Product::factory()->create(['sku' => 'LOW-001', 'name' => 'Faible', 'quantity' => 3, 'minimum_stock' => 10, 'category_id' => $category->id]);
    Product::factory()->inactive()->create(['sku' => 'OFF-001', 'name' => 'Retiré']);
    $admin = User::factory()->admin()->create();

    $skus = fn (array $query) => collect(exportRows($this->actingAs($admin)->get(route('exports.products', $query))))
        ->skip(1)->pluck(0)->sort()->values()->all();

    expect($skus([]))->toBe(['LOW-001', 'NOR-001', 'OFF-001'])
        ->and($skus(['stock' => 'low']))->toBe(['LOW-001'])
        ->and($skus(['status' => 'inactive']))->toBe(['OFF-001'])
        ->and($skus(['category' => $category->id]))->toBe(['LOW-001'])
        ->and($skus(['search' => 'norm']))->toBe(['NOR-001']);
});

test("l'export parcourt tous les produits au-delà d'un lot de 500", function () {
    $category = Category::factory()->create();
    Product::factory()->count(520)->create(['category_id' => $category->id]);

    $rows = exportRows($this->actingAs(User::factory()->admin()->create())->get(route('exports.products')));

    expect($rows)->toHaveCount(521);
    expect(collect($rows)->skip(1)->pluck(0)->unique())->toHaveCount(520);
});

// ----------------------------------------------------------------- Niveaux de stock

test("l'export des niveaux de stock ne contient que les produits actifs", function () {
    Product::factory()->create(['sku' => 'NOR-001', 'quantity' => 50, 'minimum_stock' => 10]);
    Product::factory()->create(['sku' => 'LOW-001', 'quantity' => 4, 'minimum_stock' => 10]);
    Product::factory()->create(['sku' => 'OUT-001', 'quantity' => 0, 'minimum_stock' => 10]);
    Product::factory()->inactive()->create(['sku' => 'OFF-001']);

    $admin = User::factory()->admin()->create();

    $rows = exportRows($this->actingAs($admin)->get(route('exports.stock-levels', ['status' => 'inactive'])));

    $bySku = collect($rows)->skip(1)->keyBy(0);

    expect($bySku->keys()->sort()->values()->all())->toBe(['LOW-001', 'NOR-001', 'OUT-001'])
        ->and($bySku['NOR-001'][7])->toBe('Stock normal')
        ->and($bySku['NOR-001'][8])->toBe('0')
        ->and($bySku['LOW-001'][7])->toBe('Stock faible')
        ->and($bySku['LOW-001'][8])->toBe('6')
        ->and($bySku['OUT-001'][7])->toBe('Rupture')
        ->and($bySku['OUT-001'][8])->toBe('10');
});

// ----------------------------------------------------------------- Mouvements

test("l'export de l'historique contient les colonnes attendues et respecte les filtres", function () {
    $user = User::factory()->create(['name' => 'Awa Traoré']);
    $product = Product::factory()->create(['sku' => 'ALI-001', 'name' => 'Aliment', 'quantity' => 0]);
    $service = app(StockMovementService::class);

    $service->recordEntry($product, 20, $user, comment: 'Livraison', occurredAt: now()->subDays(3));
    $service->recordExit($product, 5, $user, MovementReason::Sale, occurredAt: now());

    $admin = User::factory()->admin()->create();

    $rows = exportRows($this->actingAs($admin)->get(route('exports.movements')));

    expect($rows)->toHaveCount(3)
        ->and($rows[0][7])->toBe('Variation')
        // le plus récent d'abord ; la variation négative n'est pas altérée par la neutralisation
        ->and([$rows[1][2], $rows[1][4], $rows[1][5], $rows[1][7], $rows[1][8], $rows[1][9], $rows[1][10], $rows[1][11]])
        ->toBe(['ALI-001', 'Sortie', 'Vente', '-5', '20', '15', 'Awa Traoré', 'Appliqué'])
        ->and([$rows[2][4], $rows[2][6], $rows[2][7]])->toBe(['Entrée', 'Livraison', '20']);

    $types = exportRows($this->actingAs($admin)->get(route('exports.movements', ['type' => 'exit'])));
    expect($types)->toHaveCount(2)->and($types[1][4])->toBe('Sortie');

    $recent = exportRows($this->actingAs($admin)->get(route('exports.movements', [
        'from' => now()->subDay()->format('Y-m-d'),
    ])));
    expect($recent)->toHaveCount(2)->and($recent[1][4])->toBe('Sortie');
});

test("l'export de l'historique refuse des filtres invalides", function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('exports.movements', ['from' => 'pas-une-date']))
        ->assertSessionHasErrors('from');
});

// ----------------------------------------------------------------- Sécurité du CSV

test('les formules sont neutralisées dans les cellules texte', function () {
    $category = Category::factory()->create();
    $names = [
        '=HYPERLINK("http://evil.test","clic")',
        '+1+1',
        '-2+3',
        '@SUM(A1)',
        "\t=1+1",
        'Aliment normal',
    ];

    foreach ($names as $i => $name) {
        Product::factory()->create(['sku' => 'INJ-00'.$i, 'name' => $name, 'category_id' => $category->id]);
    }

    $rows = exportRows($this->actingAs(User::factory()->admin()->create())->get(route('exports.products')));
    $exported = collect($rows)->skip(1)->sortBy(0)->pluck(2)->values()->all();

    expect($exported)->toBe([
        "'".'=HYPERLINK("http://evil.test","clic")',
        "'+1+1",
        "'-2+3",
        "'@SUM(A1)",
        "'\t=1+1",
        'Aliment normal',
    ]);
});

test('les commentaires de mouvement sont neutralisés aussi', function () {
    $user = User::factory()->create();
    app(StockMovementService::class)->recordEntry(
        Product::factory()->create(['quantity' => 0]),
        5,
        $user,
        MovementReason::Other,
        '=cmd|\' /C calc\'!A0',
    );

    $rows = exportRows($this->actingAs(User::factory()->admin()->create())->get(route('exports.movements')));

    expect($rows[1][6])->toBe("'=cmd|' /C calc'!A0");
});

test('la neutralisation ne touche que les textes dangereux', function () {
    expect(CsvExport::neutralize('=1+1'))->toBe("'=1+1")
        ->and(CsvExport::neutralize('+33'))->toBe("'+33")
        ->and(CsvExport::neutralize('-1'))->toBe("'-1")
        ->and(CsvExport::neutralize('@a'))->toBe("'@a")
        ->and(CsvExport::neutralize("\t1"))->toBe("'\t1")
        ->and(CsvExport::neutralize("\r1"))->toBe("'\r1")
        ->and(CsvExport::neutralize('Aliment'))->toBe('Aliment')
        ->and(CsvExport::neutralize('Prix = 5'))->toBe('Prix = 5')
        ->and(CsvExport::neutralize(''))->toBe('')
        ->and(CsvExport::neutralize(-5))->toBe(-5)
        ->and(CsvExport::neutralize(12.5))->toBe(12.5)
        ->and(CsvExport::neutralize(null))->toBeNull();
});

test('les montants sont formatés pour un tableur en français', function () {
    expect(CsvExport::decimal('18500.00'))->toBe('18500,00')
        ->and(CsvExport::decimal('1234.5'))->toBe('1234,50')
        ->and(CsvExport::decimal(0))->toBe('0,00')
        ->and(CsvExport::decimal(null))->toBeNull();
});

// ----------------------------------------------------------------- Traçabilité

test('chaque export est tracé avec ses filtres', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('exports.products', ['stock' => 'low', 'search' => 'ali']));
    $this->actingAs($admin)->get(route('exports.movements'));

    $logs = AuditLog::orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->action)->toBe('data.exported')
        ->and($logs[0]->user_id)->toBe($admin->id)
        ->and($logs[0]->entity_type)->toBe('export')
        ->and($logs[0]->details)->toBe(['export' => 'products', 'filters' => ['search' => 'ali', 'stock' => 'low']])
        ->and($logs[1]->details)->toBe(['export' => 'movements']);
});