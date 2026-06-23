<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Http\Middleware\EnsureBranchScope;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsureMerchantActive;
use App\Http\Middleware\ResolveTenantContext;

/**
 * Route security classifications (Plan §24.1). Phase R4 introduced the minimal
 * seam needed to enforce idempotency on `financial_mutation` routes; Phase 10
 * (REM-ROUTE-001) completes the full set and the per-class required/forbidden
 * middleware contract that the RouteSecurityContractTest feature test
 * enforces — without replacing the R4 idempotency coverage guard.
 *
 * A route declares its class via route defaults under
 * {@see RouteClassification::KEY}, e.g.
 * `->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)`.
 *
 * The {@see requiredMiddleware()} / {@see forbiddenMiddleware()} lists are
 * substrings matched against the *gathered* middleware of a route (group +
 * route middleware, with aliases resolved to classes — global middleware is not
 * included by `Router::gatherRouteMiddleware()`), so they stay correct as long as
 * the framework's resolved class names hold.
 */
enum RouteClass: string
{
    case PublicMutation = 'public_mutation';
    case AuthenticatedGlobalMutation = 'authenticated_global_mutation';
    case TenantMutation = 'tenant_mutation';
    case BranchMutation = 'branch_mutation';
    case FinancialMutation = 'financial_mutation';
    case PlatformMutation = 'platform_mutation';
    case ProviderWebhookMutation = 'provider_webhook_mutation';
    case LivenessReadiness = 'liveness_readiness';

    /** Sanctum authentication (alias `auth:sanctum` resolves to this class). */
    private const AUTH = 'Illuminate\\Auth\\Middleware\\Authenticate';

    /** Any throttle limiter (`throttle:*` resolves to ThrottleRequests[WithRedis]). */
    private const THROTTLE = 'Illuminate\\Routing\\Middleware\\ThrottleRequests';

    /**
     * Middleware (substrings) every route of this class MUST carry.
     *
     * @return list<string>
     */
    public function requiredMiddleware(): array
    {
        return match ($this) {
            self::PublicMutation => [self::THROTTLE],
            self::AuthenticatedGlobalMutation => [self::AUTH],
            self::TenantMutation => [self::AUTH, ResolveTenantContext::class],
            self::BranchMutation => [self::AUTH, ResolveTenantContext::class, EnsureBranchScope::class],
            self::FinancialMutation => [self::AUTH, EnsureIdempotentRequest::class],
            self::PlatformMutation => [self::AUTH],
            self::ProviderWebhookMutation => [],
            self::LivenessReadiness => [],
        };
    }

    /**
     * Middleware (substrings) a route of this class MUST NOT carry.
     *
     * @return list<string>
     */
    public function forbiddenMiddleware(): array
    {
        return match ($this) {
            // No premature tenant resolution / Sanctum before an account exists.
            self::PublicMutation => [self::AUTH, ResolveTenantContext::class],
            // Identity-level: no merchant tenant context.
            self::AuthenticatedGlobalMutation => [ResolveTenantContext::class],
            self::TenantMutation => [],
            self::BranchMutation => [],
            self::FinancialMutation => [],
            // Platform authority only — never merchant tenant context (Plan §24.1).
            self::PlatformMutation => [ResolveTenantContext::class, EnsureMerchantActive::class],
            // Provider contract, never Sanctum / browser session controls.
            self::ProviderWebhookMutation => [self::AUTH],
            // Infrastructure probe: no user auth, no tenant context (Plan §22.1).
            self::LivenessReadiness => [self::AUTH, ResolveTenantContext::class],
        };
    }

    /** Whether a route of this class is expected to validate a request body. */
    public function requiresValidation(): bool
    {
        return match ($this) {
            self::LivenessReadiness => false,
            default => true,
        };
    }
}
