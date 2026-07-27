<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchPersonnelOptionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 23 §14.1 — the Branch Manager personnel-option source (product-owner decision).
 *
 * A NARROW, read-only branch read model: it lists the acting branch's personnel as
 * `{id, display_name}` so the read-only personnel-schedule screen can populate its picker.
 * It deliberately does NOT reuse the HR staff roster API (`GET /api/v1/staff`), which is
 * authorized by `staff.view` — an HR-only key the Branch Manager does not and must not
 * hold. Following the Phase 20G `commission-rule-service-options` precedent, the narrow
 * endpoint is gated by the permission the caller ALREADY holds (`branch.dashboard.view`);
 * no permission key was created and no role grant was widened.
 *
 * Roster semantics are not invented here: the option set is exactly the set of staff
 * profiles whose availability this caller may subsequently read, i.e. the set for which
 * `PersonnelAvailabilityPolicy::view` passes — same merchant, accessible branch. The
 * shipped Phase 15B screen applied no status filter of its own, so none is added (that
 * would narrow a verified_complete workflow); the response simply stops carrying the
 * status/role/branch metadata it never needed.
 *
 * Writes nothing, accepts no caller-supplied merchant/branch/role/owner filter, and is
 * bounded to the acting merchant + branch by the StaffProfile tenancy scopes.
 */
final class BranchPersonnelOptionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection
    {
        // Branch-dashboard-scoped gate — the SAME authority Plan §19.3 already grants the
        // Branch Manager for read-only personnel visibility, and the same key
        // PersonnelAvailabilityPolicy::view accepts. `staff.view` is never consulted.
        abort_unless($this->context->can('branch.dashboard.view'), 403);

        // Branch-scoped from the start: the StaffProfile BelongsToBranch/BelongsToMerchant
        // global scopes bound this to the acting merchant, and the explicit merchant +
        // branch predicates are defence in depth (a foreign merchant/branch personnel can
        // never appear). The `isBranchScoped()` guard mirrors StaffController::index —
        // `branchIds()` is EMPTY for a merchant-scoped principal and means "all own-merchant
        // branches", never "none", so it must not be used as a blind `whereIn`.
        $query = StaffProfile::query()
            ->where('merchant_id', $this->context->merchantId());

        if ($this->context->isBranchScoped()) {
            $query->whereIn('primary_branch_id', $this->context->branchIds());
        }

        // Deterministic order (name, then public ULID) keeps the option list stable.
        $personnel = $query->orderBy('display_name')->orderBy('ulid')->get();

        return BranchPersonnelOptionResource::collection($personnel);
    }
}
