<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        Gate::define('export-data', fn (User $user) => $user->is_active && $user->isAdmin());
        Gate::define('view-analysis', fn (User $user) => $user->is_active && $user->isAdmin());

        RateLimiter::for('sync', function (Request $request) {
            $perMinute = (int) config('stockflow.sync.rate_limit_per_minute');
            $token = $request->bearerToken();

            return [
                Limit::perMinute($perMinute * 3)->by('ip:'.$request->ip()),
                Limit::perMinute($perMinute)->by('token:'.($token ? hash('sha256', $token) : 'none')),
            ];
        });
    }
}
