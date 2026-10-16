<?php

use App\Enums\MovementReason;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMovementException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function stock(): StockMovementService
{
    return app(StockMovementService::class);
}

test('une entrée augmente le stock et enregistre un mouvement complet', function () {
    $product = Product::factory()->create(['quantity' => 10]);
    $user = User::factory()->create();

    $movement = stock()->recordEntry($product, 25, $user, comment: 'Livraison du lundi');

    expect($product->fresh()->quantity)->toBe(35)
        ->and($product->quantity)->toBe(35);

    expect($movement->type)->toBe(MovementType::Entry)
        ->and($movement->reason_code)->toBe(MovementReason::Reception)
        ->and($movement->quantity)->toBe(25)
        ->and($movement->quantity_before)->toBe(10)
        ->and($movement->quantity_after)->toBe(35)
        ->and($movement->user_id)->toBe($user->id)
        ->and($movement->status)->toBe(MovementStatus::Applied)
        ->and($movement->reason)->toBe('Livraison du lundi')
        ->and($movement->uuid)->not->toBeEmpty();
});

test('une sortie valide diminue le stock', function () {
    $product = Product::factory()->create(['quantity' => 40]);

    $movement = stock()->recordExit($product, 15, User::factory()->create(), MovementReason::Sale);

    expect($product->fresh()->quantity)->toBe(25);
    expect($movement->quantity_before)->toBe(40)
        ->and($movement->quantity_after)->toBe(25)
        ->and($movement->type)->toBe(MovementType::Exit);
});

test('une sortie de la totalité du stock est autorisée', function () {
    $product = Product::factory()->create(['quantity' => 12]);

    stock()->recordExit($product, 12, User::factory()->create(), MovementReason::Sale);

    expect($product->fresh()->quantity)->toBe(0);
});

test('une sortie supérieure au stock est refusée et ne modifie rien', function () {
    $product = Product::factory()->create(['quantity' => 5]);
    $user = User::factory()->create();

    expect(fn () => stock()->recordExit($product, 6, $user, MovementReason::Sale))
        ->toThrow(InsufficientStockException::class);

    expect($product->fresh()->quantity)->toBe(5);
    expect(StockMovement::count())->toBe(0);
});

test("l'exception de stock insuffisant indique les quantités", function () {
    $product = Product::factory()->create(['quantity' => 5]);

    try {
        stock()->recordExit($product, 8, User::factory()->create(), MovementReason::Sale);
        $this->fail('Une exception était attendue.');
    } catch (InsufficientStockException $e) {
        expect($e->available)->toBe(5)
            ->and($e->requested)->toBe(8)
            ->and($e->getMessage())->toContain('5')->toContain('8');
    }
});

test('une quantité nulle ou négative est refusée', function () {
    $product = Product::factory()->create(['quantity' => 10]);
    $user = User::factory()->create();

    foreach ([0, -5] as $invalid) {
        expect(fn () => stock()->recordEntry($product, $invalid, $user))
            ->toThrow(StockMovementException::class);
        expect(fn () => stock()->recordExit($product, $invalid, $user, MovementReason::Sale))
            ->toThrow(StockMovementException::class);
    }

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::count())->toBe(0);
});

test('le motif doit correspondre au type de mouvement', function () {
    $product = Product::factory()->create(['quantity' => 10]);
    $user = User::factory()->create();

    expect(fn () => stock()->recordEntry($product, 5, $user, MovementReason::Sale))
        ->toThrow(StockMovementException::class);
    expect(fn () => stock()->recordEntry($product, 5, $user, MovementReason::Theft))
        ->toThrow(StockMovementException::class);
    expect(fn () => stock()->recordExit($product, 5, $user, MovementReason::Reception))
        ->toThrow(StockMovementException::class);

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::count())->toBe(0);
});

test('le motif « autre » exige un commentaire', function () {
    $product = Product::factory()->create(['quantity' => 10]);
    $user = User::factory()->create();

    expect(fn () => stock()->recordExit($product, 2, $user, MovementReason::Other))
        ->toThrow(StockMovementException::class);
    expect(fn () => stock()->recordExit($product, 2, $user, MovementReason::Other, '   '))
        ->toThrow(StockMovementException::class);

    stock()->recordExit($product, 2, $user, MovementReason::Other, 'Don à une association');

    expect($product->fresh()->quantity)->toBe(8);
});

test('un produit inactif ne reçoit plus de nouvelles opérations', function () {
    $product = Product::factory()->inactive()->create(['quantity' => 10]);

    expect(fn () => stock()->recordEntry($product, 5, User::factory()->create()))
        ->toThrow(StockMovementException::class);

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::count())->toBe(0);
});

test('un utilisateur désactivé ne peut pas enregistrer de mouvement', function () {
    $product = Product::factory()->create(['quantity' => 10]);

    expect(fn () => stock()->recordEntry($product, 5, User::factory()->inactive()->create()))
        ->toThrow(StockMovementException::class);

    expect($product->fresh()->quantity)->toBe(10);
});

test('si l\'enregistrement du mouvement échoue, le stock est restauré', function () {
    $product = Product::factory()->create(['quantity' => 10]);

    // Utilisateur inexistant en base : la mise à jour du produit réussit,
    // puis l'insertion du mouvement échoue sur la clé étrangère.
    $ghost = User::factory()->make(['is_active' => true]);
    $ghost->id = 999999;

    expect(fn () => stock()->recordEntry($product, 5, $ghost))
        ->toThrow(QueryException::class);

    expect($product->fresh()->quantity)->toBe(10);
    expect(StockMovement::count())->toBe(0);
});

test('les mouvements successifs forment une chaîne cohérente', function () {
    $product = Product::factory()->create(['quantity' => 0]);
    $user = User::factory()->create();

    stock()->recordEntry($product, 100, $user);
    stock()->recordExit($product, 30, $user, MovementReason::Sale);
    stock()->recordExit($product, 20, $user, MovementReason::Loss);
    stock()->recordEntry($product, 5, $user);

    $movements = StockMovement::where('product_id', $product->id)->orderBy('id')->get();

    expect($movements->pluck('quantity_before')->all())->toBe([0, 100, 70, 50]);
    expect($movements->pluck('quantity_after')->all())->toBe([100, 70, 50, 55]);
    expect($product->fresh()->quantity)->toBe(55);
});

test("la date de l'opération peut être fournie, la date d'enregistrement est celle du serveur", function () {
    $product = Product::factory()->create(['quantity' => 0]);
    $when = now()->subDays(3);

    $movement = stock()->recordEntry($product, 10, User::factory()->create(), occurredAt: $when);

    expect($movement->occurred_at->toDateTimeString())->toBe($when->toDateTimeString());
    expect($movement->recorded_at->greaterThan($movement->occurred_at))->toBeTrue();
});

test('un mouvement appliqué ne peut être ni modifié ni supprimé', function () {
    $movement = stock()->recordEntry(
        Product::factory()->create(['quantity' => 1]),
        5,
        User::factory()->create(),
    );

    expect(fn () => $movement->update(['quantity' => 999]))->toThrow(LogicException::class);
    expect(fn () => $movement->delete())->toThrow(LogicException::class);

    expect($movement->fresh()->quantity)->toBe(5);
    expect(StockMovement::count())->toBe(1);
});

test('la base refuse un mouvement de quantité nulle', function () {
    $product = Product::factory()->create();
    $user = User::factory()->create();

    expect(fn () => DB::table('stock_movements')->insert([
        'uuid' => (string) Str::uuid(),
        'product_id' => $product->id,
        'user_id' => $user->id,
        'type' => 'entry',
        'reason_code' => 'reception',
        'quantity' => 0,
        'occurred_at' => now(),
        'recorded_at' => now(),
    ]))->toThrow(QueryException::class);
});