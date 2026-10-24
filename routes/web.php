<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\MovementCorrectionController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ExpiryController;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    
    // route de catergories
    Route::resource('categories', CategoryController::class)->except('destroy');
    Route::patch('categories/{category}/toggle', [CategoryController::class, 'toggle'])
        ->name('categories.toggle');

    // route de fournisseurs
    Route::resource('suppliers', SupplierController::class)->except('destroy');
    Route::patch('suppliers/{supplier}/toggle', [SupplierController::class, 'toggle'])
    ->name('suppliers.toggle');

    // route de produits
    Route::resource('products', ProductController::class)->except('destroy');
    Route::patch('products/{product}/toggle', [ProductController::class, 'toggle'])
    ->name('products.toggle');
    //
    Route::get('movements', [StockMovementController::class, 'index'])->name('movements.index');
    Route::get('products/{product}/movements/{type}/create', [StockMovementController::class, 'create'])
        ->whereIn('type', ['entry', 'exit', 'adjustment'])->name('movements.create');
    Route::post('products/{product}/movements/{type}', [StockMovementController::class, 'store'])
        ->whereIn('type', ['entry', 'exit', 'adjustment'])->name('movements.store');
    Route::get('movements/{movement}/correct', [MovementCorrectionController::class, 'create'])
        ->name('movements.correct.create');
    Route::post('movements/{movement}/correct', [MovementCorrectionController::class, 'store'])
        ->name('movements.correct.store');
    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

    Route::middleware('can:export-data')->prefix('exports')->name('exports.')->group(function () {
        Route::get('products', [ExportController::class, 'products'])->name('products');
        Route::get('stock-levels', [ExportController::class, 'stockLevels'])->name('stock-levels');
        Route::get('movements', [ExportController::class, 'movements'])->name('movements');
    });

    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::patch('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
    Route::get('users/{user}/password', [UserController::class, 'editPassword'])->name('users.password.edit');
    Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password.update');

    Route::get('expiries', [ExpiryController::class, 'index'])->name('expiries.index');
    Route::post('products/{product}/expired-lots', [ExpiryController::class, 'remove'])->name('expiries.remove');
});

require __DIR__.'/auth.php';
