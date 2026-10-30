<?php

use App\Enums\MovementReason;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReplenishmentAdvisor;
use App\Services\StockMovementService;

function replenishmentProduct(array $attributes = [], int $quantity = 20, int $consumed = 60): Product
{
    $product = Product::factory()->create(array_merge(['quantity' => 0], $attributes));
    $user = User::factory()->create();
    $service = app(StockMovementService::class);

    $service->recordEntry($product, $quantity + $consumed, $user, occurredAt: now()->subDays(40));

    if ($consumed > 0) {
        $service->recordExit($product, $consumed, $user, MovementReason::Sale, occurredAt: now()->subDays(5));
    }

    return $product->fresh();
}

function agroPlus(array $overrides = []): Supplier
{
    return Supplier::factory()->create(array_merge([
        'name' => 'Agro Plus',
        'contact_name' => 'Awa Traoré',
        'phone' => '+22670123456',
        'email' => 'awa@agroplus.test',
        'lead_time_days' => 5,
    ], $overrides));
}

// ------------------------------------------------------------------ Suggestions

test('la quantité suggérée couvre le délai du fournisseur puis la couverture cible', function () {
    $supplier = Supplier::factory()->create(['lead_time_days' => 5]);
    $product = replenishmentProduct(['supplier_id' => $supplier->id, 'minimum_stock' => 10], quantity: 20);

    $suggestion = app(ReplenishmentAdvisor::class)->suggestions()->first();

    expect($suggestion->product->is($product))->toBeTrue()
        ->and($suggestion->quantity)->toBe(50)           // 2 par jour × (5 + 30) − 20
        ->and($suggestion->basis)->toBe('consumption')
        ->and($suggestion->leadTimeDays)->toBe(5);
});

test('la quantité est arrondie à l\'unité supérieure', function () {
    $supplier = Supplier::factory()->create(['lead_time_days' => 5]);
    replenishmentProduct(['supplier_id' => $supplier->id], quantity: 10, consumed: 100);

    // 100 / 30 = 3,333 par jour × 35 jours − 10 = 106,67
    expect(app(ReplenishmentAdvisor::class)->suggestions()->first()->quantity)->toBe(107);
});

test('un produit sans fournisseur utilise le délai par défaut', function () {
    replenishmentProduct(quantity: 10, consumed: 60);

    $suggestion = app(ReplenishmentAdvisor::class)->suggestions()->first();

    expect($suggestion->leadTimeDays)->toBe(7)
        ->and($suggestion->quantity)->toBe(64);          // 2 × (7 + 30) − 10
});

test('un produit confortable n\'est pas suggéré', function () {
    replenishmentProduct(quantity: 300);

    expect(app(ReplenishmentAdvisor::class)->suggestions())->toBeEmpty();
});

test('sans estimation possible, la suggestion vise le double du seuil minimal', function () {
    Product::factory()->create(['quantity' => 4, 'minimum_stock' => 10]);

    $suggestion = app(ReplenishmentAdvisor::class)->suggestions()->first();

    expect($suggestion->quantity)->toBe(16)              // 2 × 10 − 4
        ->and($suggestion->basis)->toBe('threshold');
});

test('un produit en alerte classique mais déjà très couvert n\'est pas suggéré', function () {
    // 5 en stock pour un seuil de 10, mais une seule unité vendue en un mois.
    replenishmentProduct(['minimum_stock' => 10], quantity: 5, consumed: 1);

    expect(app(ReplenishmentAdvisor::class)->suggestions())->toBeEmpty();
});

test('les produits inactifs ne sont pas suggérés', function () {
    replenishmentProduct(quantity: 5)->update(['is_active' => false]);

    expect(app(ReplenishmentAdvisor::class)->suggestions())->toBeEmpty();
});

test('les suggestions sont triées : ruptures, couverture croissante, puis produits sans estimation', function () {
    replenishmentProduct(['name' => 'C attention'], quantity: 20);       // 10 jours
    Product::factory()->create(['name' => 'D sans historique', 'quantity' => 4, 'minimum_stock' => 10]);
    replenishmentProduct(['name' => 'B critique'], quantity: 10);        // 5 jours
    replenishmentProduct(['name' => 'A rupture'], quantity: 0);          // 0 jour

    expect(app(ReplenishmentAdvisor::class)->suggestions()->pluck('product.name')->all())
        ->toBe(['A rupture', 'B critique', 'C attention', 'D sans historique']);
});

// ------------------------------------------------------------------ Message et liens

test('le message récapitule la commande avec le nom du contact et la signature', function () {
    $supplier = new Supplier(['name' => 'Agro Plus', 'contact_name' => 'Awa Traoré']);
    $user = new User(['name' => 'Bruno Gestionnaire']);
    $items = collect([
        ['product' => new Product(['name' => 'Aliment croissance', 'sku' => 'ALI-002', 'unit' => 'sac']), 'quantity' => 80],
        ['product' => new Product(['name' => 'Aliment ponte', 'sku' => 'ALI-003', 'unit' => 'sac']), 'quantity' => 70],
    ]);

    expect(app(ReplenishmentAdvisor::class)->message($supplier, $items, $user))->toBe(implode("\n", [
        'Bonjour Awa Traoré,',
        '',
        'Nous souhaitons passer la commande suivante :',
        '- Aliment croissance (ALI-002) : 80 sac',
        '- Aliment ponte (ALI-003) : 70 sac',
        '',
        'Merci de nous confirmer la disponibilité et le délai de livraison.',
        'Cordialement,',
        'Bruno Gestionnaire',
    ]));

    $anonymous = app(ReplenishmentAdvisor::class)->message(new Supplier(['name' => 'X']), $items, $user);
    expect($anonymous)->toStartWith("Bonjour,\n");
});

test('le lien WhatsApp utilise le numéro international sans le plus et encode le message', function () {
    $advisor = app(ReplenishmentAdvisor::class);

    expect($advisor->whatsappUrl(new Supplier(['phone' => '+22670123456']), "Bonjour\nCommande & suite"))
        ->toBe('https://wa.me/22670123456?text=Bonjour%0ACommande%20%26%20suite');

    foreach ([null, '', '70123456', '+0123456789', 'abc', '+226-70-12'] as $invalid) {
        expect($advisor->whatsappUrl(new Supplier(['phone' => $invalid]), 'Bonjour'))->toBeNull();
    }
});

test('le lien email conserve l\'arobase et encode le sujet et le message', function () {
    $advisor = app(ReplenishmentAdvisor::class);

    expect($advisor->mailtoUrl(new Supplier(['email' => 'awa@agroplus.test']), 'Commande', "Bonjour\nMerci"))
        ->toBe('mailto:awa@agroplus.test?subject=Commande&body=Bonjour%0AMerci');

    expect($advisor->mailtoUrl(new Supplier(['email' => null]), 'Commande', 'x'))->toBeNull();
    expect($advisor->mailtoUrl(new Supplier(['email' => 'pas-un-email']), 'Commande', 'x'))->toBeNull();
});

// ------------------------------------------------------------------ Écran et préparation

test('un visiteur ne peut pas accéder au réapprovisionnement', function () {
    $this->get(route('replenishment.index'))->assertRedirect(route('login'));
    $this->post(route('replenishment.prepare', Supplier::factory()->create()))->assertRedirect(route('login'));
});

test('la page propose un message de repli quand rien n\'est à réapprovisionner', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('replenishment.index'))
        ->assertOk()
        ->assertSee('Aucun produit à réapprovisionner');
});

test('la page regroupe les produits par fournisseur avec leur quantité suggérée', function () {
    $supplier = agroPlus();
    replenishmentProduct(['name' => 'Aliment croissance', 'supplier_id' => $supplier->id], quantity: 10);
    replenishmentProduct(['name' => 'Aliment ponte', 'supplier_id' => $supplier->id], quantity: 0);
    replenishmentProduct(['name' => 'Produit orphelin'], quantity: 10);

    $this->actingAs(User::factory()->create())
        ->get(route('replenishment.index'))
        ->assertOk()
        ->assertSee('Agro Plus')
        ->assertSee('Délai de livraison : 5 j')
        ->assertSee('Aliment croissance')
        ->assertSee('Aliment ponte')
        ->assertSee(route('replenishment.prepare', $supplier), false)
        ->assertSee('Sans fournisseur')
        ->assertSee('Associe un fournisseur')
        ->assertSeeInOrder(['Agro Plus', 'Sans fournisseur']);
});

test('la préparation produit le message, les liens et une trace d\'audit sans rien envoyer', function () {
    $supplier = agroPlus();
    $a = replenishmentProduct(['name' => 'Aliment croissance', 'supplier_id' => $supplier->id], quantity: 10);
    $b = replenishmentProduct(['name' => 'Aliment ponte', 'supplier_id' => $supplier->id], quantity: 0);
    $manager = User::factory()->create(['name' => 'Bruno Gestionnaire']);
    $movementsBefore = StockMovement::count();

    $this->actingAs($manager)
        ->post(route('replenishment.prepare', $supplier), [
            'items' => [
                $a->id => ['include' => '1', 'quantity' => '80'],
                $b->id => ['include' => '0', 'quantity' => '70'],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('replenishment.index'))
        ->assertSessionHas('prepared', function (array $prepared) use ($a, $b) {
            return $prepared['supplier'] === 'Agro Plus'
                && str_contains($prepared['message'], 'Bonjour Awa Traoré,')
                && str_contains($prepared['message'], "- Aliment croissance ({$a->sku}) : 80 unité")
                && ! str_contains($prepared['message'], 'Aliment ponte')
                && str_contains($prepared['message'], 'Bruno Gestionnaire')
                && $prepared['whatsapp'] === 'https://wa.me/22670123456?text='.rawurlencode($prepared['message'])
                && str_starts_with($prepared['mailto'], 'mailto:awa@agroplus.test?subject=Commande&body=');
        });

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe('replenishment.prepared')
        ->and($log->user_id)->toBe($manager->id)
        ->and($log->entity_type)->toBe('supplier')
        ->and($log->entity_id)->toBe($supplier->id)
        ->and($log->details['items'])->toBe("{$a->sku} × 80");

    // Aucun effet sur le stock : la préparation d'un message n'est pas une commande.
    expect(StockMovement::count())->toBe($movementsBefore)
        ->and($a->fresh()->quantity)->toBe(10);
});

test('après la préparation, la page affiche le message et les boutons', function () {
    $supplier = agroPlus();
    $product = replenishmentProduct(['supplier_id' => $supplier->id], quantity: 10);

    $this->actingAs(User::factory()->create())
        ->followingRedirects()
        ->post(route('replenishment.prepare', $supplier), [
            'items' => [$product->id => ['include' => '1', 'quantity' => '60']],
        ])
        ->assertOk()
        ->assertSee('Message prêt pour Agro Plus')
        ->assertSee('Ouvrir WhatsApp')
        ->assertSee("Ouvrir l'email", false)
        ->assertSee('Copier le message');
});

test('sans téléphone valide, WhatsApp est indisponible mais le message reste utilisable', function () {
    $supplier = agroPlus(['phone' => null]);
    $product = replenishmentProduct(['supplier_id' => $supplier->id], quantity: 10);

    $this->actingAs(User::factory()->create())
        ->followingRedirects()
        ->post(route('replenishment.prepare', $supplier), [
            'items' => [$product->id => ['include' => '1', 'quantity' => '60']],
        ])
        ->assertSee('WhatsApp indisponible')
        ->assertDontSee('Ouvrir WhatsApp')
        ->assertSee('Copier le message');
});

test('au moins un produit doit être sélectionné, avec une quantité valide', function () {
    $supplier = agroPlus();
    $product = replenishmentProduct(['supplier_id' => $supplier->id], quantity: 10);
    $manager = User::factory()->create();

    $this->actingAs($manager)
        ->post(route('replenishment.prepare', $supplier), [])
        ->assertSessionHasErrors('items');

    $this->actingAs($manager)
        ->post(route('replenishment.prepare', $supplier), [
            'items' => [$product->id => ['include' => '0', 'quantity' => '50']],
        ])
        ->assertSessionHasErrors('items');

    $this->actingAs($manager)
        ->post(route('replenishment.prepare', $supplier), [
            'items' => [$product->id => ['include' => '1', 'quantity' => '']],
        ])
        ->assertSessionHasErrors('items');

    foreach ([0, -1, 'abc', 2.5] as $invalid) {
        $this->actingAs($manager)
            ->post(route('replenishment.prepare', $supplier), [
                'items' => [$product->id => ['include' => '1', 'quantity' => $invalid]],
            ])
            ->assertSessionHasErrors("items.{$product->id}.quantity");
    }

    expect(AuditLog::count())->toBe(0);
});

test('un produit d\'un autre fournisseur ou inactif est refusé', function () {
    $supplier = agroPlus();
    $other = Supplier::factory()->create();
    $foreign = replenishmentProduct(['supplier_id' => $other->id], quantity: 10);
    $inactive = replenishmentProduct(['supplier_id' => $supplier->id], quantity: 10);
    $inactive->update(['is_active' => false]);
    $manager = User::factory()->create();

    foreach ([$foreign, $inactive] as $product) {
        $this->actingAs($manager)
            ->post(route('replenishment.prepare', $supplier), [
                'items' => [$product->id => ['include' => '1', 'quantity' => '50']],
            ])
            ->assertSessionHasErrors('form');
    }

    expect(AuditLog::count())->toBe(0);
});

test('un fournisseur désactivé ne reçoit aucun message', function () {
    $supplier = agroPlus(['is_active' => false]);
    $product = replenishmentProduct(['supplier_id' => $supplier->id], quantity: 10);

    $this->actingAs(User::factory()->create())
        ->get(route('replenishment.index'))
        ->assertSee('fournisseur désactivé')
        ->assertDontSee(route('replenishment.prepare', $supplier), false);

    $this->actingAs(User::factory()->create())
        ->post(route('replenishment.prepare', $supplier), [
            'items' => [$product->id => ['include' => '1', 'quantity' => '50']],
        ])
        ->assertSessionHasErrors('form');

    expect(AuditLog::count())->toBe(0);
});

test('le contenu du message est échappé à l\'affichage', function () {
    $supplier = agroPlus();
    $product = replenishmentProduct([
        'supplier_id' => $supplier->id,
        'name' => '</textarea><script>alert(1)</script>',
    ], quantity: 10);

    $this->actingAs(User::factory()->create())
        ->followingRedirects()
        ->post(route('replenishment.prepare', $supplier), [
            'items' => [$product->id => ['include' => '1', 'quantity' => '60']],
        ])
        ->assertOk()
        ->assertDontSee('</textarea><script>alert(1)</script>', false)
        ->assertSee('&lt;/textarea&gt;&lt;script&gt;', false);
});

test('la préparation apparaît dans le journal d\'audit', function () {
    $supplier = agroPlus();
    $product = replenishmentProduct(['supplier_id' => $supplier->id], quantity: 10);

    $this->actingAs(User::factory()->create())
        ->post(route('replenishment.prepare', $supplier), [
            'items' => [$product->id => ['include' => '1', 'quantity' => '60']],
        ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('audit.index'))
        ->assertOk()
        ->assertSee('Réapprovisionnement préparé')
        ->assertSee("Fournisseur n° {$supplier->id}");
});