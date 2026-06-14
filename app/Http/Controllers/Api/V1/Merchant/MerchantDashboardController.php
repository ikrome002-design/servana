<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantResource;
use Illuminate\Http\JsonResponse;

/**
 * Merchant dashboard shell (Plan §27 Phase 6).
 *
 * GET /merchant/dashboard — gated by EnsureMerchantActive, so reaching it proves
 * the active-merchant boundary. Returns only a safe shell summary; the real
 * dashboard widgets/reports are owned by later operational phases.
 */
final class MerchantDashboardController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        $merchant = $context->merchant();
        abort_if($merchant === null, 403);

        return response()->json([
            'data' => [
                'merchant' => MerchantResource::make($merchant),
                'shell' => [
                    'sections' => ['overview', 'branches', 'staff', 'reports'],
                    'ready' => true,
                ],
            ],
        ]);
    }
}
