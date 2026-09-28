<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        \Illuminate\Support\Facades\RateLimiter::for('agent', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by(hash('sha256',$r->bearerToken() ?? $r->ip())));
        \Laravel\Horizon\Horizon::auth(fn ($request) => $request->user()?->active && $request->user()?->role === 'owner');
    }
}
