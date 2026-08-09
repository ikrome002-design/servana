<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\PlatformFeatureFlags\Actions\DecideFeatureFlagChange;
use App\Domain\PlatformFeatureFlags\Actions\PausePlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Actions\RequestFeatureFlagChange;
use App\Domain\PlatformFeatureFlags\Exceptions\PlatformFeatureFlagException;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagChangeRequest;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagHistory;
use App\Domain\PlatformFeatureFlags\Services\PlatformFeatureFlagCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\DecideFeatureFlagChangeRequest;
use App\Http\Requests\Platform\PauseFeatureFlagRequest;
use App\Http\Requests\Platform\RequestFeatureFlagChangeRequest;
use App\Http\Resources\PlatformFeatureFlagChangeRequestResource;
use App\Http\Resources\PlatformFeatureFlagResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform feature flags (COR-UI08-001 §12; navigation map §5.4.20, /platform/feature-flags).
 *
 * Reads require `platform.settings.view`; mutations require `platform.settings.update` plus MFA and
 * a fresh `platform_feature_flag_change` step-up. **No feature-flag-specific permission key exists.**
 *
 * THE CATALOGUE IS THE CODE ALLOWLIST. Every operation joins the persisted state to
 * `config/platform-feature-flags.php`; an unknown key is a `404`, and there is deliberately no
 * create endpoint — an operator can never mint a flag at runtime. An empty catalogue returns an
 * empty collection, which is a truthful state, not an error.
 */
final class PlatformFeatureFlagController extends Controller
{
    public function __construct(private readonly PlatformFeatureFlagCatalogue $catalogue) {}

    public function index(): JsonResponse
    {
        $this->authorize('view', PlatformFeatureFlag::class);

        $environment = (string) app()->environment();

        $states = PlatformFeatureFlag::query()
            ->where('environment', $environment)
            ->with('targets')
            ->get()
            ->keyBy('flag_key');

        $data = [];

        foreach ($this->catalogue->all() as $key => $definition) {
            $data[] = PlatformFeatureFlagResource::payload($definition, $states->get($key));
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'environment' => $environment,
                'catalogue_size' => count($data),
                // An empty catalogue is truthful: no platform feature flag has been authorized.
                'catalogue_is_empty' => $this->catalogue->isEmpty(),
                'catalogue_source' => 'config/platform-feature-flags.php',
                'note' => 'The catalogue is code. The API cannot create a flag key, and an unknown key is rejected.',
            ],
        ]);
    }

    public function show(string $flagKey): JsonResponse
    {
        $this->authorize('view', PlatformFeatureFlag::class);

        $definition = $this->catalogue->definition($flagKey);

        if ($definition === null) {
            throw PlatformFeatureFlagException::unknownFlagKey($flagKey);
        }

        $state = PlatformFeatureFlag::query()
            ->where('flag_key', $flagKey)
            ->where('environment', app()->environment())
            ->with('targets')
            ->first();

        return response()->json(['data' => PlatformFeatureFlagResource::payload($definition, $state)]);
    }

    public function history(string $flagKey): JsonResponse
    {
        $this->authorize('view', PlatformFeatureFlag::class);

        if (! $this->catalogue->has($flagKey)) {
            throw PlatformFeatureFlagException::unknownFlagKey($flagKey);
        }

        $flag = PlatformFeatureFlag::query()
            ->where('flag_key', $flagKey)
            ->where('environment', app()->environment())
            ->first();

        $history = $flag === null
            ? collect()
            : PlatformFeatureFlagHistory::query()
                ->where('feature_flag_id', $flag->id)
                ->with('actor')
                ->orderByDesc('id')
                ->limit(100)
                ->get();

        return response()->json([
            'data' => $history->map(static fn (PlatformFeatureFlagHistory $row): array => [
                'id' => $row->ulid,
                'action' => $row->action,
                'before_hash' => $row->before_hash,
                'after_hash' => $row->after_hash,
                'before_configuration' => $row->before_configuration,
                'after_configuration' => $row->after_configuration,
                'actor' => $row->actor?->ulid,
                'reason' => $row->reason,
                'correlation_id' => $row->correlation_id,
                'created_at' => $row->created_at->toIso8601String(),
            ])->all(),
            'meta' => ['append_only' => true],
        ]);
    }

    public function requestChange(
        RequestFeatureFlagChangeRequest $request,
        string $flagKey,
        RequestFeatureFlagChange $action,
    ): JsonResponse {
        $this->authorize('update', PlatformFeatureFlag::class);

        /** @var User $actor */
        $actor = $request->user();

        $changeRequest = $action->handle(
            $flagKey,
            $request->configuration(),
            $request->governance(),
            $actor,
        );

        return response()->json(
            ['data' => PlatformFeatureFlagChangeRequestResource::make($changeRequest)->resolve()],
            Response::HTTP_CREATED,
        );
    }

    public function approve(
        DecideFeatureFlagChangeRequest $request,
        PlatformFeatureFlagChangeRequest $platformFeatureFlagChangeRequest,
        DecideFeatureFlagChange $action,
    ): PlatformFeatureFlagChangeRequestResource {
        $this->authorize('update', PlatformFeatureFlag::class);

        /** @var User $actor */
        $actor = $request->user();

        return PlatformFeatureFlagChangeRequestResource::make(
            $action->approve($platformFeatureFlagChangeRequest, $actor),
        );
    }

    public function reject(
        DecideFeatureFlagChangeRequest $request,
        PlatformFeatureFlagChangeRequest $platformFeatureFlagChangeRequest,
        DecideFeatureFlagChange $action,
    ): PlatformFeatureFlagChangeRequestResource {
        $this->authorize('update', PlatformFeatureFlag::class);

        /** @var User $actor */
        $actor = $request->user();

        return PlatformFeatureFlagChangeRequestResource::make(
            $action->reject($platformFeatureFlagChangeRequest, (string) $request->validated('reason'), $actor),
        );
    }

    public function cancel(
        DecideFeatureFlagChangeRequest $request,
        PlatformFeatureFlagChangeRequest $platformFeatureFlagChangeRequest,
        DecideFeatureFlagChange $action,
    ): PlatformFeatureFlagChangeRequestResource {
        $this->authorize('update', PlatformFeatureFlag::class);

        /** @var User $actor */
        $actor = $request->user();

        return PlatformFeatureFlagChangeRequestResource::make(
            $action->cancel($platformFeatureFlagChangeRequest, $actor),
        );
    }

    public function pause(
        PauseFeatureFlagRequest $request,
        string $flagKey,
        PausePlatformFeatureFlag $action,
    ): JsonResponse {
        $this->authorize('update', PlatformFeatureFlag::class);

        if (! $this->catalogue->has($flagKey)) {
            throw PlatformFeatureFlagException::unknownFlagKey($flagKey);
        }

        $flag = PlatformFeatureFlag::query()
            ->where('flag_key', $flagKey)
            ->where('environment', app()->environment())
            ->first();

        if ($flag === null) {
            throw PlatformFeatureFlagException::unknownFlagKey($flagKey);
        }

        /** @var User $actor */
        $actor = $request->user();

        $paused = $action->handle($flag, (string) $request->validated('reason'), $actor);

        return response()->json([
            'data' => PlatformFeatureFlagResource::payload(
                $this->catalogue->definition($flagKey),
                $paused->load('targets'),
            ),
        ]);
    }
}
