<?php

declare(strict_types=1);

namespace App\Http\Routing;

/**
 * Route security classifications (Plan §24.1). Phase R4 introduces the minimal
 * seam needed to enforce idempotency on `financial_mutation` routes; Phase 10
 * extends this enum + the full RouteSecurityContractTest without replacing it.
 *
 * A route declares its class via route defaults under
 * {@see RouteClassification::KEY}, e.g.
 * `->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)`.
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
}
