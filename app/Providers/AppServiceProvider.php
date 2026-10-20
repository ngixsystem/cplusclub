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
        \Illuminate\Support\Facades\Gate::policy(\App\Domain\Knowledge\Article::class, \App\Policies\KnowledgePolicy::class);
        \Illuminate\Support\Facades\RateLimiter::for('agent', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by(hash('sha256',$r->bearerToken() ?? $r->ip())));

        // Named limiters: plain `throttle:N,1` shares ONE counter per user across every
        // route, so dashboard polling used to exhaust the 5/min login and password limits.
        // Each limiter below counts only its own requests.
        $limit = \Illuminate\Cache\RateLimiting\Limit::class;
        $who = fn ($r) => (string) ($r->user()?->getAuthIdentifier() ?? $r->ip());
        // Login: 5 tries per account per IP, plus a looser per-IP cap. Club staff often
        // share one NAT address, so the IP-only cap must allow several people at once.
        \Illuminate\Support\Facades\RateLimiter::for('login', fn ($r) => [
            $limit::perMinute(5)->by('login|'.mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            $limit::perMinute(30)->by('login-ip|'.$r->ip()),
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('password', fn ($r) => $limit::perMinute(5)->by('password|'.$who($r)));
        \Illuminate\Support\Facades\RateLimiter::for('avatar', fn ($r) => $limit::perMinute(10)->by('avatar|'.$who($r)));
        // Live widgets (dashboard, map, monitoring) poll; each endpoint gets its own budget.
        \Illuminate\Support\Facades\RateLimiter::for('live', fn ($r) => $limit::perMinute(60)->by('live|'.$r->route()?->uri().'|'.$who($r)));
        \Illuminate\Support\Facades\RateLimiter::for('leads', fn ($r) => $limit::perMinute(20)->by('leads|'.$r->ip()));
        \Laravel\Horizon\Horizon::auth(fn ($request) => $request->user()?->active && $request->user()?->role === 'owner');
    }
}
