<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Domain\Merchants\Enums\ServiceFeeTier;
use App\Domain\Onboarding\Actions\CompleteFirstTimeSetup;
use App\Domain\Onboarding\Data\FirstTimeSetupData;
use App\Domain\Onboarding\Services\FirstTimeSetupProgress;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CompleteFirstTimeSetupRequest;
use App\Http\Resources\MerchantResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First-time setup (Scope §3.2 steps 1–7). Access (pending_setup +
 * merchant_admin) is gated by EnsureFirstTimeSetupAccess middleware.
 *
 *   GET  /merchant-registration/first-time-setup  → current progress + options
 *   POST /merchant-registration/first-time-setup  → complete setup (transactional)
 */
final class FirstTimeSetupController extends Controller
{
    public function show(Request $request, TenantContext $context, FirstTimeSetupProgress $progress): JsonResponse
    {
        $merchant = $context->merchant();

        // Middleware guarantees a pending_setup merchant context here.
        abort_if($merchant === null, 403);

        return response()->json([
            'data' => [
                'merchant' => MerchantResource::make($merchant),
                'setup' => [
                    'required' => $progress->required($merchant),
                    'current_step' => $progress->currentStep($merchant),
                ],
                'options' => [
                    'service_fee_tiers' => array_map(
                        static fn (ServiceFeeTier $tier): array => [
                            'value' => $tier->value,
                            'label' => $tier->label(),
                        ],
                        ServiceFeeTier::cases(),
                    ),
                ],
            ],
        ]);
    }

    public function store(
        CompleteFirstTimeSetupRequest $request,
        TenantContext $context,
        CompleteFirstTimeSetup $action,
    ): JsonResponse {
        $merchant = $context->merchant();
        abort_if($merchant === null, 403);

        /** @var User $actor */
        $actor = $request->user();

        $merchant = $action->handle(
            $merchant,
            $actor,
            FirstTimeSetupData::fromArray($request->validated()),
        );

        // Step 7 — signal the SPA to redirect the now-active owner to the dashboard.
        return response()->json([
            'data' => [
                'merchant' => MerchantResource::make($merchant),
                'redirect' => 'merchant.dashboard',
            ],
        ]);
    }
}
