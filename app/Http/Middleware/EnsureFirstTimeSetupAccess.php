<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the first-time setup endpoints (Scope §3.2, Plan §8.1).
 *
 * Setup is the ONLY merchant surface a pending_setup merchant may reach, and
 * only its Merchant Administrator (owner) may drive it:
 *
 *   - no merchant context              → 403 no_tenant_context
 *   - not the merchant_admin owner     → 403 no_tenant_context (no role leak)
 *   - merchant already active          → 409 setup_already_completed
 *   - suspended/deactivated merchant   → 403 merchant_suspended
 *   - pending_setup + merchant_admin   → allowed
 */
final class EnsureFirstTimeSetupAccess
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->hasMerchant() || $this->context->role() !== MerchantUserRole::MerchantAdmin) {
            throw TenantAccessException::noTenantContext();
        }

        if ($this->context->isActiveMerchant()) {
            throw TenantAccessException::setupAlreadyCompleted();
        }

        if (! $this->context->isPendingSetup()) {
            // Suspended / deactivated merchant — never reaches setup.
            throw TenantAccessException::merchantSuspended();
        }

        return $next($request);
    }
}
