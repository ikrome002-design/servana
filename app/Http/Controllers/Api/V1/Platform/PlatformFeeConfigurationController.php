<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\ApprovePlatformFeeConfiguration;
use App\Domain\Billing\Actions\CancelPlatformFeeConfiguration;
use App\Domain\Billing\Actions\CreatePlatformFeeConfiguration;
use App\Domain\Billing\Actions\SupersedePlatformFeeConfiguration;
use App\Domain\Billing\Actions\UpdatePlatformFeeConfigurationDraft;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ChangeReasonRequest;
use App\Http\Requests\Billing\StorePlatformFeeConfigurationRequest;
use App\Http\Resources\PlatformFeeConfigurationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Percentage platform-fee configuration API (Plan §51, §52; Phase 20E, Increment 6). Super-Admin
 * platform scope; MFA (group) + fresh BillingConfiguration step-up (mutating routes) + idempotency on
 * create/approve/supersede/cancel. Named actions per transition — NO generic status route; the
 * controller never assigns a status string. Thin: authorize → validate → action → masked Resource.
 */
final class PlatformFeeConfigurationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlatformFeeConfiguration::class);

        $query = PlatformFeeConfiguration::query()->orderByDesc('effective_from');

        if (in_array($request->string('status')->value(), PlatformFeeConfigurationStatus::values(), true)) {
            $query->where('status', $request->string('status')->value());
        }

        return PlatformFeeConfigurationResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function store(StorePlatformFeeConfigurationRequest $request, CreatePlatformFeeConfiguration $action): JsonResponse
    {
        $this->authorize('manage', PlatformFeeConfiguration::class);

        /** @var array<string,mixed> $data */
        $data = $request->validated();

        return PlatformFeeConfigurationResource::make($action->handle($data, $this->actor($request)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PlatformFeeConfiguration $platformFeeConfiguration): PlatformFeeConfigurationResource
    {
        $this->authorize('view', $platformFeeConfiguration);

        return PlatformFeeConfigurationResource::make($platformFeeConfiguration);
    }

    public function update(StorePlatformFeeConfigurationRequest $request, PlatformFeeConfiguration $platformFeeConfiguration, UpdatePlatformFeeConfigurationDraft $action): PlatformFeeConfigurationResource
    {
        $this->authorize('manage', $platformFeeConfiguration);

        /** @var array<string,mixed> $data */
        $data = $request->validated();

        return PlatformFeeConfigurationResource::make($action->handle($platformFeeConfiguration, $data, $this->actor($request)));
    }

    public function approve(ChangeReasonRequest $request, PlatformFeeConfiguration $platformFeeConfiguration, ApprovePlatformFeeConfiguration $action): PlatformFeeConfigurationResource
    {
        $this->authorize('manage', $platformFeeConfiguration);

        return PlatformFeeConfigurationResource::make(
            $action->handle($platformFeeConfiguration, $this->actor($request), (string) $request->validated('change_reason')),
        );
    }

    public function supersede(StorePlatformFeeConfigurationRequest $request, PlatformFeeConfiguration $platformFeeConfiguration, SupersedePlatformFeeConfiguration $action): JsonResponse
    {
        $this->authorize('manage', $platformFeeConfiguration);

        /** @var array<string,mixed> $data */
        $data = $request->validated();

        return PlatformFeeConfigurationResource::make($action->handle($platformFeeConfiguration, $data, $this->actor($request)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function cancel(ChangeReasonRequest $request, PlatformFeeConfiguration $platformFeeConfiguration, CancelPlatformFeeConfiguration $action): PlatformFeeConfigurationResource
    {
        $this->authorize('manage', $platformFeeConfiguration);

        return PlatformFeeConfigurationResource::make(
            $action->handle($platformFeeConfiguration, $this->actor($request), (string) $request->validated('change_reason')),
        );
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
