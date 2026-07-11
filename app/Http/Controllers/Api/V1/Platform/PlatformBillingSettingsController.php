<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\UpdatePlatformBillingSettings;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\UpdatePlatformBillingSettingsRequest;
use App\Http\Resources\PlatformBillingSettingsResource;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform billing-settings read/update (Plan §13.9, §47, §50; Phase 20A). Super-Admin platform
 * scope; MFA (group) + fresh step-up (route) enforce authorization. Update appends a NEW effective
 * version. Thin: authorize → action → resource.
 */
final class PlatformBillingSettingsController extends Controller
{
    public function show(): PlatformBillingSettingsResource
    {
        $this->authorize('view', PlatformBillingSettings::class);

        $current = PlatformBillingSettings::current();
        abort_if($current === null, Response::HTTP_NOT_FOUND);

        return PlatformBillingSettingsResource::make($current);
    }

    public function update(UpdatePlatformBillingSettingsRequest $request, UpdatePlatformBillingSettings $action): PlatformBillingSettingsResource
    {
        $this->authorize('update', PlatformBillingSettings::class);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array{billing_mode:string,default_trial_days:int,grace_days:int,currency:string,settings?:array<string,mixed>} $data */
        $data = $request->validated();

        return PlatformBillingSettingsResource::make($action->handle($data, $actor));
    }
}
