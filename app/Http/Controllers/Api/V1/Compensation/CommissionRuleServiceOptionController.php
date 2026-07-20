<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompensationSelectableServiceResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 20G §9.1 — the HR selected-services option source (product-owner decision). A NARROW, read-only
 * compensation-configuration read model: it lists the acting branch's ACTIVE services as `{ulid, name}`
 * so HR can populate a commission rule's selected-services multi-select. It deliberately does NOT reuse
 * the branch-manager catalogue API and is authorized by the compensation permission
 * `compensation.plan.view` — never `service.view` (which HR does not and cannot hold). It exposes no
 * price/cost/management field, writes nothing, and is bounded to the acting merchant+branch by the
 * `Service` tenancy scopes (a foreign branch/merchant service can never appear).
 */
final class CommissionRuleServiceOptionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection
    {
        // Compensation-scoped gate: `viewAny` on CommissionRule maps to `compensation.plan.view`. This is
        // NOT a service-catalogue authorization — `service.view` is never consulted or widened.
        $this->authorize('viewAny', CommissionRule::class);

        // Branch-scoped from the start: the `Service` BelongsToBranch/BelongsToMerchant global scopes bound
        // this to the acting merchant+branch; `active()` excludes archived/inactive services from new
        // selection. Deterministic order (name, then public ULID) makes the option list stable.
        $services = Service::query()
            ->active()
            ->whereIn('branch_id', $this->context->branchIds())
            ->orderBy('name')
            ->orderBy('ulid')
            ->get();

        return CompensationSelectableServiceResource::collection($services);
    }
}
