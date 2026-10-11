<?php

namespace App\Providers;

use App\Services\Accounting\Ledger;
use App\Services\Messaging\LogSmsGateway;
use App\Services\Messaging\SmsGateway;
use App\Support\Tenancy\TenantManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh instance per request (and per queued job / test).
        $this->app->scoped(TenantManager::class);
        $this->app->scoped(Ledger::class);

        $this->app->bind(SmsGateway::class, function () {
            return match (config('services.sms.driver', 'log')) {
                'log' => new LogSmsGateway,
                default => throw new LogicException('Unknown SMS driver ['.config('services.sms.driver').']. See docs/SETUP.md.'),
            };
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        // Biometric endpoints cost money per call at most providers and are a
        // target for brute-force matching; keep them tight.
        RateLimiter::for('biometrics', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }
}
