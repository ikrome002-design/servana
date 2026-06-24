<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\DatabaseAuditRecorder;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\TenantContext;
use App\Policies\AuditLogPolicy;
use App\Policies\BranchDayRecordPolicy;
use App\Policies\BranchOperatingHourPolicy;
use App\Policies\MerchantBranchPolicy;
use App\Policies\MerchantPolicy;
use App\Policies\MerchantUserPolicy;
use App\Policies\StaffInvitationPolicy;
use App\Policies\StaffProfilePolicy;
use App\Support\CorrelationId;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Model → policy map (Plan §10.4).
     *
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        Merchant::class => MerchantPolicy::class,
        MerchantBranch::class => MerchantBranchPolicy::class,
        MerchantUser::class => MerchantUserPolicy::class,
        StaffInvitation::class => StaffInvitationPolicy::class,
        StaffProfile::class => StaffProfilePolicy::class,
        BranchOperatingHour::class => BranchOperatingHourPolicy::class,
        BranchDayRecord::class => BranchDayRecordPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
    ];

    public function register(): void
    {
        // Shared per-request correlation id (middleware sets it; logging and the
        // error renderer read it).
        $this->app->singleton(CorrelationId::class);

        // Per-request tenant context (Plan §8.1). `scoped` so it is a singleton
        // within one request and reset between requests; ResolveTenantContext
        // populates it after auth.
        $this->app->scoped(TenantContext::class);

        // Audit trail (Plan §22.2). Table-backed minimal recorder introduced in
        // Phase 8; full §5.18 coverage matures in Phase 19.
        $this->app->bind(AuditRecorder::class, DatabaseAuditRecorder::class);

        // Must run in register() — before dedoc/scramble's provider boots and
        // registers its default docs routes (Phase 10).
        $this->configureOpenApiGenerator();
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerPolicies();
    }

    /**
     * Configure the maintained dedoc/scramble generator (ADR / Phase 10).
     *
     * The generator only ever analyses the current production `/api/v1` surface —
     * the test-only harness routes (registered under APP_ENV=testing) are excluded
     * at the source, so the document is identical across environments and Scramble
     * never analyses a harness closure. The default docs UI routes are not
     * registered: Servana ships the committed `docs/api/openapi.json` artifact, not
     * a live docs endpoint.
     */
    private function configureOpenApiGenerator(): void
    {
        Scramble::ignoreDefaultRoutes();

        Scramble::routes(function (Route $route): bool {
            $uri = $route->uri();
            $name = $route->getName() ?? '';

            return str_starts_with($uri, 'api/v1/')
                && ! str_contains($uri, 'api/v1/testing/')
                && ! str_starts_with($name, 'testing.');
        });
    }

    /** Register the §10.4 model policies. */
    private function registerPolicies(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
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

        // MFA confirmation and challenge attempts (Plan §18, §9.3). Per-user
        // (authenticated) and per-IP, so brute-forcing a 6-digit code or a
        // recovery code is throttled to a structured 429.
        RateLimiter::for('mfa-confirm', fn (Request $request) => [
            Limit::perMinute(5)->by($this->identify($request)),
        ]);

        RateLimiter::for('mfa-challenge', fn (Request $request) => [
            Limit::perMinute(5)->by($this->identify($request)),
            Limit::perMinute(15)->by('ip:'.(string) $request->ip()),
        ]);

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
