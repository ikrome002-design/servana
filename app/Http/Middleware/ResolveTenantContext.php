<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Sessions\Services\SessionBindingResolver;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextResolver;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the per-request tenant context (Plan §8.1). Runs AFTER auth:sanctum.
 *
 * Resolution only — it never denies a request. It populates the request-scoped
 * TenantContext singleton so downstream middleware (EnsureMerchantActive,
 * EnsureFirstTimeSetupAccess) and resources (/me) can read a consistent view:
 *
 *   - platform staff  → marked as platform staff, no merchant
 *   - merchant user   → the membership their SESSION is bound to, or their single
 *                       active membership when the request carries no binding
 *   - neither         → context left empty (downstream gates decide)
 *
 * Keeping denial out of resolution lets /me work for any authenticated state
 * (incl. pending_setup) while the active/pending gates stay explicit per-route.
 *
 * Phase UI-03: WHICH membership a browser request operates as is decided by
 * {@see SessionBindingResolver}, not here. This file deliberately knows nothing
 * about hosts — `AccountHostDoesNotAuthorizeTest` proves no account-host
 * reference reaches an authorization layer, because a host that reaches an
 * authorization decision has become authorization (ADR-017). The binding arrives
 * as a neutral, already-verified answer.
 */
final class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantContextResolver $resolver,
        private readonly SessionBindingResolver $bindings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $this->resolver->populate(
            $this->context,
            $user instanceof User ? $user : null,
            $user instanceof User ? $this->bindings->forRequest($request, $user) : null,
        );

        return $next($request);
    }

    /**
     * Clear the resolved context once the response has been sent (Plan §8.2).
     * The TenantContext is a container-`scoped` singleton; resetting it here
     * guarantees no merchant scope leaks past the request boundary — important
     * both for long-lived workers and so back-to-back test requests never bleed
     * a previous merchant into an out-of-request Eloquent query.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->context->reset();
    }
}
