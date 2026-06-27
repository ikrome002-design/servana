<?php

declare(strict_types=1);

use App\Domain\Files\Jobs\DeleteExpiredQuarantineFile;
use App\Domain\Files\Jobs\ExpireSignedExport;
use App\Domain\Files\Jobs\VerifyOrphanedFileRecords;
use App\Exceptions\ApiErrorRenderer;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsureActivePrincipal;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsurePrivilegedMfa;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Routing\RouteClass;
use App\Http\Routing\RouteClassification;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Health probes live outside the web group: only global middleware,
            // so no session/CSRF/database dependency (Plan §22.1).
            Route::get('/health', [HealthController::class, 'live'])
                ->defaults(RouteClassification::KEY, RouteClass::LivenessReadiness->value)
                ->name('health');
            Route::get('/health/deep', [HealthController::class, 'deep'])
                ->defaults(RouteClassification::KEY, RouteClass::LivenessReadiness->value)
                ->name('health.deep');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Assign the correlation id first so it is available to logging and the
        // error envelope on every request (Plan §11.5, §22.1).
        $middleware->prepend(CorrelationIdMiddleware::class);

        // Sanctum SPA mode (Plan §9.2): first-party stateful cookie sessions for
        // the /api/v1 surface. Prepends EnsureFrontendRequestsAreStateful to the
        // `api` group so requests from SANCTUM_STATEFUL_DOMAINS use the session
        // guard + CSRF instead of bearer tokens.
        $middleware->statefulApi();

        // Tenant context must be resolved BEFORE route-model binding so bindings
        // resolve inside merchant scope (Plan §8.2): a foreign-tenant ULID then
        // 404s (and audits) at binding time. This pins ResolveTenantContext just
        // ahead of SubstituteBindings in the framework's default priority order.
        //
        // EnsurePrivilegedMfa is pinned BETWEEN authentication and tenant
        // context (Plan §18, §9.4 step 2): mandatory-role MFA state is checked
        // immediately after auth and before any tenant resolution.
        //
        // EnsureActivePrincipal is pinned BETWEEN authentication and MFA (Plan
        // §79 R6 ordering): a suspended/deactivated principal is rejected right
        // after auth, before any MFA, tenant or permission work runs.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesRequests::class,
            EnsureActivePrincipal::class,
            EnsurePrivilegedMfa::class,
            ResolveTenantContext::class,
            SubstituteBindings::class,
            AuthenticatesSessions::class,
            Authorize::class,
            // Idempotency runs last — immediately before the controller — so the
            // claim is taken after all authorization gates pass (Plan §9.4 step
            // 16, §10.2: "idempotency middleware (financial)").
            EnsureIdempotentRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report to Sentry — a no-op while SENTRY_LARAVEL_DSN is empty.
        Integration::handles($exceptions);

        // Render API / JSON exceptions as the structured envelope (Plan §11.5).
        $exceptions->render(function (Throwable $e, Request $request) {
            $renderer = app(ApiErrorRenderer::class);

            return $renderer->shouldHandle($request)
                ? $renderer->render($e, $request)
                : null;
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        // File-domain maintenance (Plan §65, §67; Phase 10F). Bounded, idempotent,
        // tenant/platform-safe jobs on the file-scanning queue. VerifyOrphanedFileRecords
        // only REPORTS mismatches — it never deletes unknown production objects.
        $schedule->job(new ExpireSignedExport)->hourly();
        $schedule->job(new DeleteExpiredQuarantineFile)->hourly();
        $schedule->job(new VerifyOrphanedFileRecords)->daily();
    })->create();
