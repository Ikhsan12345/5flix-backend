<?php

// app/Providers/RouteServiceProvider.php
// Tambahkan custom rate limiting

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        // API Rate Limiting
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Auth Rate Limiting (Login/Register)
        RateLimiter::for('auth', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perDay(20)->by($request->ip())
            ];
        });

        // Public API Rate Limiting
        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });

        // Admin API Rate Limiting
        RateLimiter::for('admin', function (Request $request) {
            return $request->user()?->role === 'admin'
                ? Limit::perMinute(120)->by($request->user()->id)
                : Limit::perMinute(30)->by($request->ip());
        });

        // Download Rate Limiting
        RateLimiter::for('download', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(50)->by($request->user()?->id ?: $request->ip())
            ];
        });
    }
}