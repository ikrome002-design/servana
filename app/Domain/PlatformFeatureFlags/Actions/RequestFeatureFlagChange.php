<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagChangeRequestStatus;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Domain\PlatformFeatureFlags\Exceptions\PlatformFeatureFlagException;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagChangeRequest;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagHistory;
use App\Domain\PlatformFeatureFlags\Services\PlatformFeatureFlagCatalogue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Propose a feature-flag change (COR-UI08-001 §12.3; Phase UI-08).
 *
 * This is the ONLY way a flag turns on. The request records the mandatory impact statement, rollback
 * plan, health criterion and reason, and it must then be approved by a DIFFERENT administrator.
 *
 * The flag row is created lazily here when the key exists in the code catalogue but has no state row
 * for this environment yet — which is why an operator never "creates a flag": they propose a change
 * to one the catalogue already defines.
 */
final class RequestFeatureFlagChange
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformFeatureFlagCatalogue $catalogue,
    ) {}

    /**
     * @param  array{state:string,rollout_basis_points:int,effective_from?:string|null,effective_to?:string|null,targets?:list<array{type:string,value:string}>}  $configuration
     * @param  array{impact_statement:string,rollback_plan:string,health_criterion:string,reason:string}  $governance
     */
    public function handle(string $flagKey, array $configuration, array $governance, User $actor): PlatformFeatureFlagChangeRequest
    {
        $definition = $this->catalogue->definition($flagKey);

        // The code allowlist is the first gate: the API can never mint a key.
        if ($definition === null) {
            throw PlatformFeatureFlagException::unknownFlagKey($flagKey);
        }

        $environment = (string) app()->environment();

        if (! $definition->supportsEnvironment($environment)) {
            throw PlatformFeatureFlagException::environmentNotSupported($environment);
        }

        foreach ($configuration['targets'] ?? [] as $target) {
            if (! $definition->supportsTargetType($target['type'])) {
                throw PlatformFeatureFlagException::targetTypeNotSupported($target['type']);
            }
        }

        return DB::transaction(function () use ($flagKey, $environment, $configuration, $governance, $actor): PlatformFeatureFlagChangeRequest {
            $flag = PlatformFeatureFlag::query()->firstOrCreate(
                ['flag_key' => $flagKey, 'environment' => $environment],
                [
                    'state' => PlatformFeatureFlagState::Inactive,
                    'rollout_basis_points' => 0,
                    'version' => 1,
                ],
            );

            $pending = PlatformFeatureFlagChangeRequest::query()
                ->where('feature_flag_id', $flag->id)
                ->where('status', PlatformFeatureFlagChangeRequestStatus::Pending->value)
                ->lockForUpdate()
                ->exists();

            if ($pending) {
                throw PlatformFeatureFlagException::pendingRequestExists();
            }

            $proposed = [
                'state' => $configuration['state'],
                'rollout_basis_points' => $configuration['rollout_basis_points'],
                'effective_from' => $configuration['effective_from'] ?? null,
                'effective_to' => $configuration['effective_to'] ?? null,
                'targets' => collect($configuration['targets'] ?? [])
                    ->map(static fn (array $target): string => $target['type'].':'.$target['value'])
                    ->sort()
                    ->values()
                    ->all(),
            ];

            $request = PlatformFeatureFlagChangeRequest::query()->create([
                'feature_flag_id' => $flag->id,
                'status' => PlatformFeatureFlagChangeRequestStatus::Pending,
                'proposed_configuration' => $proposed,
                'proposed_configuration_hash' => PlatformFeatureFlagChangeRequest::hashConfiguration($proposed),
                'impact_statement' => $governance['impact_statement'],
                'rollback_plan' => $governance['rollback_plan'],
                'health_criterion' => $governance['health_criterion'],
                'reason' => $governance['reason'],
                'requested_by_user_id' => $actor->id,
                'requested_at' => now(),
            ]);

            PlatformFeatureFlagHistory::query()->create([
                'feature_flag_id' => $flag->id,
                'change_request_id' => $request->id,
                'action' => 'change_requested',
                'before_configuration' => $flag->load('targets')->configuration(),
                'after_configuration' => $proposed,
                'before_hash' => PlatformFeatureFlagChangeRequest::hashConfiguration($flag->configuration()),
                'after_hash' => $request->proposed_configuration_hash,
                'actor_user_id' => $actor->id,
                'reason' => $governance['reason'],
                'correlation_id' => (string) Str::ulid(),
            ]);

            $this->audit->record(AuditEvent::PlatformFeatureFlagChangeRequested, $actor, null, null, $request, [
                'flag_key' => $flagKey,
                'environment' => $environment,
                'request_id' => $request->ulid,
                'proposed_hash' => $request->proposed_configuration_hash,
            ]);

            return $request;
        });
    }
}
