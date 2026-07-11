<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\UpdatePlatformSettings;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\UpdatePlatformSettingsRequest;
use App\Http\Resources\PlatformBillingSettingsResource;
use App\Models\User;
use App\Policies\PlatformSettingsPolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * General platform-settings read/update (Plan §19.3 `PlatformSettingsPolicy`; Phase 20A).
 * Super-Admin platform scope; MFA (group) + fresh step-up (route). Update appends a NEW effective
 * version of `platform_billing_settings` changing only the general settings map. Thin.
 */
final class PlatformSettingsController extends Controller
{
    public function show(Request $request): PlatformBillingSettingsResource
    {
        $this->guard($request, 'view');

        $current = PlatformBillingSettings::current();
        abort_if($current === null, Response::HTTP_NOT_FOUND);

        return PlatformBillingSettingsResource::make($current);
    }

    public function update(UpdatePlatformSettingsRequest $request, UpdatePlatformSettings $action): PlatformBillingSettingsResource
    {
        $this->guard($request, 'update');

        /** @var User $actor */
        $actor = $request->user();

        /** @var array<string,mixed> $settings */
        $settings = $request->validated('settings');

        return PlatformBillingSettingsResource::make($action->handle($settings, $actor));
    }

    private function guard(Request $request, string $ability): void
    {
        $user = $request->user();
        abort_unless($user !== null && app(PlatformSettingsPolicy::class)->{$ability}($user), Response::HTTP_FORBIDDEN);
    }
}
