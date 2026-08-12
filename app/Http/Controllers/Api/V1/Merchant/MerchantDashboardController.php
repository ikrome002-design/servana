<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Services\MerchantOwnerDashboardReadModel;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantResource;
use Illuminate\Http\JsonResponse;

/**
 * Merchant Administrator owner dashboard (UI/UX plan §6.1/§6.4.2; Phase UI-09).
 *
 * GET /merchant/dashboard — gated by EnsureMerchantActive, so reaching it proves
 * the active-merchant boundary. The read model aggregates only already-completed,
 * merchant-scoped domains. Gate-W reporting is named as unavailable and never
 * represented by fabricated zero revenue/performance values.
 */
final class MerchantDashboardController extends Controller
{
    public function show(TenantContext $context, MerchantOwnerDashboardReadModel $dashboard): JsonResponse
    {
        abort_unless($context->role() === MerchantUserRole::MerchantAdmin, 403);

        $merchant = $context->merchant();
        abort_if($merchant === null, 403);

        return response()->json([
            'data' => [
                'merchant' => MerchantResource::make($merchant),
                'overview' => $dashboard->read($merchant),
            ],
        ]);
    }
}
