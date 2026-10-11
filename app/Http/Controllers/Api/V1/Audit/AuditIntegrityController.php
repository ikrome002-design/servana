<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditChainSegmentVerifier;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditIntegrityResource;

/**
 * Audit-chain integrity status for the Audit account (Phase UI-15; Appendix A §12.4.13;
 * Plan §70, ADR-008).
 *
 * READ-ONLY. Runs the bounded segment verification over the caller's own merchant chain and
 * returns a branch-safe summary. There is no write, repair, re-hash or "run full verification"
 * action here, and no parameter: the merchant comes from the tenant context and the segment size
 * is fixed server-side.
 */
final class AuditIntegrityController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(AuditChainSegmentVerifier $verifier): AuditIntegrityResource
    {
        $this->authorize('viewAny', AuditLog::class);

        $merchantId = $this->context->merchantId();
        $branchIds = $this->context->branchIds();

        // A branch-scoped reader with no assigned branch has nothing to review; reporting the
        // merchant chain to them would widen their scope rather than describe it.
        if ($merchantId === null || $branchIds === []) {
            throw TenantAccessException::noBranchScope();
        }

        return new AuditIntegrityResource($verifier->verifyMerchantSegment($merchantId), $branchIds);
    }
}
