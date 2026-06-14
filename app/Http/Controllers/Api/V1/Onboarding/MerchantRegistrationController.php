<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Domain\Auth\Actions\RequestMagicLink;
use App\Domain\Onboarding\Actions\RegisterMerchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\RegisterMerchantRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Merchant Administrator self-registration (Scope §3.1/§3.2).
 *
 * POST /merchant-registration/self-register — public. Creates the merchant
 * tenant + owner membership and sends the owner a Magic Link to sign in and
 * begin first-time setup. The response is uniform whether or not the email was
 * new, so registration cannot enumerate existing accounts (Plan §9.1 rule).
 *
 * There is deliberately no Super Admin approval, no KYC, and no platform
 * merchant-creation route anywhere (Scope §3.1 exclusions).
 */
final class MerchantRegistrationController extends Controller
{
    public function selfRegister(
        RegisterMerchantRequest $request,
        RegisterMerchant $register,
        RequestMagicLink $requestMagicLink,
    ): JsonResponse {
        $merchant = $register->handle(
            $request->ownerName(),
            $request->email(),
            $request->businessName(),
        );

        // Only a freshly created owner is eligible; for an existing email no
        // merchant was created and no link is sent. Either way the response is
        // identical (no enumeration).
        if ($merchant !== null) {
            $requestMagicLink->handle($request->email(), $request->ip(), $request->userAgent());
        }

        return response()->json([
            'message' => 'If this is a new business, we have sent a sign-in link to continue setup. Please check your email.',
        ], Response::HTTP_ACCEPTED);
    }
}
