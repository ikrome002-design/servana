<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\CorrelationId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared per-request correlation id (middleware sets it; logging and the
        // error renderer read it).
        $this->app->singleton(CorrelationId::class);
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * Named, Redis-backed rate limiters (Plan §9.3). Routes are attached to
     * these in their owning phases; registering them here makes the names
     * available platform-wide.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('magic-link-request', fn (Request $request) => [
            Limit::perMinute(3)->by('email:'.(string) $request->input('email')),
            Limit::perHour(10)->by('ip:'.(string) $request->ip()),
        ]);

        RateLimiter::for('magic-link-verify', fn (Request $request) => Limit::perMinute(10)->by('ip:'.(string) $request->ip()));

        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(3)->by('ip:'.(string) $request->ip()));

        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perHour(10)->by('ip:'.(string) $request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($this->identify($request)));

        RateLimiter::for('finance-sensitive', fn (Request $request) => Limit::perMinute(30)->by($this->identify($request)));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by($this->identify($request)));
    }

    /** Per-user key when authenticated, otherwise per-IP. */
    private function identify(Request $request): string
    {
        return $request->user()?->getAuthIdentifier() !== null
            ? 'user:'.$request->user()->getAuthIdentifier()
            : 'ip:'.(string) $request->ip();
    }
}
