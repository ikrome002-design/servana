<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing-status mutation gate (Plan §22, §9.4 step 9; Phase 20B). The real replacement for the
 * temporary Phase 17 / Phase 10F billing-read-only seams.
 *
 * Reads ONLY `merchants.billing_status` (never `merchant_subscriptions.status`). When billing access
 * is `read_only_grace` or `suspended_billing`, merchant MUTATION routes are blocked (403
 * `billing_read_only`) while read routes and existing-file downloads continue. `trialing`, `active`,
 * and `overdue` allow mutations (Plan §25.2 — grace/suspension are the only blocking states).
 *
 * Attach to merchant mutation routes AFTER ResolveTenantContext + EnsureMerchantActive. Operational
 * `merchants.status` is a separate gate (EnsureMerchantActive) and is unaffected.
 */
final class EnsureBillingMutable
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $merchant = $this->context->merchant();

        if ($merchant !== null && $merchant->billingBlocksMutations()) {
            throw TenantAccessException::billingReadOnly();
        }

        return $next($request);
    }
}
