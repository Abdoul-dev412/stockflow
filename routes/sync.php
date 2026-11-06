<?php

use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('operations', [SyncController::class, 'operations'])->name('operations');
Route::get('changes', [SyncController::class, 'changes'])->name('changes');