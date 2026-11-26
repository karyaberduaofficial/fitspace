<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Eliminate multi-tab SQLite lock contention for fast concurrent sessions
        if (config('database.default') === 'sqlite') {
            try {
                $dbPath = (string) config('database.connections.sqlite.database', '');
                if ($dbPath !== '' && $dbPath !== ':memory:' && is_file($dbPath)) {
                    DB::statement('PRAGMA journal_mode = WAL;');
                    DB::statement('PRAGMA synchronous = NORMAL;');
                    DB::statement('PRAGMA busy_timeout = 5000;');
                    DB::statement('PRAGMA temp_store = MEMORY;');
                }
            } catch (\Throwable) {
                // Safe no-op during early Artisan bootstrap
            }
        }

        RateLimiter::for('auth', function (Request $request) {
            $key = strtolower((string) $request->input('email', '')).'|'.$request->ip();

            return Limit::perMinute(30)->by($key);
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(240)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('exports', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
    }
}
