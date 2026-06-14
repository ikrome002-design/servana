<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
 *   - merchant user   → their single active membership + merchant are bound
 *   - neither         → context left empty (downstream gates decide)
 *
 * Keeping denial out of resolution lets /me work for any authenticated state
 * (incl. pending_setup) while the active/pending gates stay explicit per-route.
 */
final class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $this->resolver->populate($this->context, $user instanceof User ? $user : null);

        return $next($request);
    }
}
