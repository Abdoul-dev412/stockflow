<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware([
                \App\Http\Middleware\ForceJson::class,
                'throttle:sync',
                \App\Http\Middleware\SecurityHeaders::class,
                'api',
                \App\Http\Middleware\AuthenticateDevice::class,
            ])->prefix('sync')->name('sync.')->group(base_path('routes/sync.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', [
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\EnsurePasswordIsChanged::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();