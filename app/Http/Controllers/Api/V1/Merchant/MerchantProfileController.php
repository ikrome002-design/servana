<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Domain\Merchants\Actions\UpdateMerchantProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchants\UpdateMerchantProfileRequest;
use App\Http\Resources\MerchantProfileResource;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant business profile (REM-SCR-002A; Plan §27.3 Merchant Administrator "merchant
 * profile"). Merchant scope, Merchant Administrator only.
 *
 * The merchant is resolved from the caller's membership — there is NO `{merchant}` binding, so
 * no request can name another tenant's profile. Reads carry `merchant.profile.view`; the update
 * carries `merchant.profile.update` plus the billing-mutable gate (the matrix records
 * `billing_read_only_behavior: block` for the write and `allow_read` for the read).
 *
 * The logo is NOT uploaded here: `POST /api/v1/files` with `purpose=merchant_logo` is the
 * Phase 10F pipeline (magic-byte MIME, ClamAV, quarantine, private storage, signed download),
 * and duplicating it would be a second, unscanned path.
 */
final class MerchantProfileController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(Request $request): MerchantProfileResource
    {
        $profile = $this->ownProfileOrFail();
        $this->authorize('view', $profile);

        return MerchantProfileResource::make($profile->load('merchant'));
    }

    public function update(UpdateMerchantProfileRequest $request, UpdateMerchantProfile $action): MerchantProfileResource
    {
        $profile = $this->ownProfileOrFail();
        $this->authorize('update', $profile);

        /** @var Merchant $merchant */
        $merchant = $profile->merchant;
        /** @var User $actor */
        $actor = $request->user();

        $updated = $action->handle($merchant, $request->writableAttributes(), $actor);

        return MerchantProfileResource::make($updated->load('merchant'));
    }

    /**
     * The caller's OWN merchant profile. Never client-selectable: the merchant comes from the
     * resolved tenant context. A merchant without a profile shell is a 404, not an empty object.
     */
    private function ownProfileOrFail(): MerchantProfile
    {
        $merchantId = $this->context->merchantId();

        $profile = $merchantId === null
            ? null
            : MerchantProfile::query()->where('merchant_id', $merchantId)->first();

        if ($profile === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $profile;
    }
}
