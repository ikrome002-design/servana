<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate merchant operational routes (Plan §8.1). Runs AFTER ResolveTenantContext.
 *
 *   - no merchant context        → 403 no_tenant_context
 *   - merchant pending_setup     → 403 pending_setup_only (SPA → setup wizard)
 *   - merchant suspended/deactiv → 403 merchant_suspended
 *   - merchant active            → allowed
 *
 * This is the security boundary for the dashboard shell and (in later phases)
 * every merchant resource route. Suspended/deactivated read-only allowlisting
 * (Plan §8.1) is layered in by the phases that own those historical endpoints.
 */
final class EnsureMerchantActive
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->hasMerchant()) {
            throw TenantAccessException::noTenantContext();
        }

        if ($this->context->isPendingSetup()) {
            throw TenantAccessException::pendingSetupOnly();
        }

        if (! $this->context->isActiveMerchant()) {
            throw TenantAccessException::merchantSuspended();
        }

        return $next($request);
    }
}
