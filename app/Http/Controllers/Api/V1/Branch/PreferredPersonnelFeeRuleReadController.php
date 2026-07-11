<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branch;

use App\Domain\Billing\Queries\ResolveEffectivePreferredPersonnelFee;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\PreferredPersonnelFeeBranchRuleResource;
use App\Policies\PreferredPersonnelFeeRulePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branch read of the EFFECTIVE preferred-personnel fee rule (Plan §13.10, §19.3; Phase 20A).
 * Branch-scoped, read-only, `preferred_personnel_fee.view_branch_rule` (Branch Manager; NO
 * platform MFA/step-up). Returns ONLY the applicable effective rule's public-safe terms — the
 * platform default, or the service-specific rule when a `service` ULID (in the branch's tenant) is
 * supplied. Never draft/scheduled admin metadata, status, approval internals, or ids. Uses
 * {@see PreferredPersonnelFeeRuleResource} shape only via the masked branch resource.
 */
final class PreferredPersonnelFeeRuleReadController extends Controller
{
    public function show(Request $request, ResolveEffectivePreferredPersonnelFee $resolver, TenantContext $context): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && app(PreferredPersonnelFeeRulePolicy::class)->viewBranchRule($user), Response::HTTP_FORBIDDEN);

        $serviceId = null;
        if ($request->filled('service')) {
            // Resolve within the branch's tenant scope; a foreign/unknown service 404s (no leak).
            $serviceId = Service::query()->where('ulid', $request->string('service')->value())->value('id');
            abort_if($serviceId === null, Response::HTTP_NOT_FOUND);
        }

        $rule = $resolver->rule($serviceId, CarbonImmutable::now('Africa/Nairobi'));

        return response()->json([
            'data' => $rule === null ? null : PreferredPersonnelFeeBranchRuleResource::make($rule)->resolve($request),
        ]);
    }
}
