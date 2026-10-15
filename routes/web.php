<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\ProductController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
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
});

require __DIR__.'/auth.php';
