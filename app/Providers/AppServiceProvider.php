<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Brute-force protection: 5 attempts per minute per e-mail + IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Provider webhooks can burst (SendGrid batches up to 1000 events), but a flood from one IP is not legitimate.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        // Open pixels / click redirects / unsubscribe pages are public.
        RateLimiter::for('tracking', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
        RateLimiter::for('unsubscribe', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Heavy actions inside the admin panel.
        RateLimiter::for('sending-actions', fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->id ?: $request->ip()));
        RateLimiter::for('test-email', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id ?: $request->ip()));
    }
}
