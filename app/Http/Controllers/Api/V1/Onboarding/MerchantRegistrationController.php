<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Domain\Auth\Actions\RequestMagicLink;
use App\Domain\Onboarding\Actions\RegisterMerchant;
use App\Http\Controllers\Controller;
use App\Http\Hosts\AccountHostRegistry;
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
    /** Self-registration produces a Merchant Administrator, and only ever that (Scope §3.1). */
    private const SELF_REGISTRATION_ACCOUNT_KEY = 'merchant_administrator';

    public function selfRegister(
        RegisterMerchantRequest $request,
        RegisterMerchant $register,
        RequestMagicLink $requestMagicLink,
        AccountHostRegistry $hosts,
    ): JsonResponse {
        $merchant = $register->handle(
            $request->ownerName(),
            $request->email(),
            $request->businessName(),
            // Phase 21R-A (Plan §58A.1): optional referral intent. Null when none was submitted;
            // a malformed code still arrives here and is stored as invalid_format evidence — it is
            // never a reason to reject the registration.
            $request->referralCapture(),
        );

        // Only a freshly created owner is eligible; for an existing email no
        // merchant was created and no link is sent. Either way the response is
        // identical (no enumeration).
        if ($merchant !== null) {
            // Phase UI-03 (ADR-019): the registration link is bound like any other. The account is
            // NOT taken from the request — self-registration creates exactly one kind of principal,
            // a Merchant Administrator, so the account key and its host come from the registry.
            // That also means a registration link cannot be replayed on a staff host.
            $environment = $hosts->environment();

            $requestMagicLink->handle(
                email: $request->email(),
                accountKey: self::SELF_REGISTRATION_ACCOUNT_KEY,
                host: $hosts->hostForAccount(self::SELF_REGISTRATION_ACCOUNT_KEY, $environment),
                environment: $environment,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return response()->json([
            'message' => 'If this is a new business, we have sent a sign-in link to continue setup. Please check your email.',
        ], Response::HTTP_ACCEPTED);
    }
}
