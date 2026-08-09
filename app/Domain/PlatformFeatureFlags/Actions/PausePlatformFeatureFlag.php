<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagChangeRequest;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagHistory;
use App\Domain\PlatformFeatureFlags\Services\PlatformFeatureFlagStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emergency pause (COR-UI08-001 §12; Phase UI-08).
 *
 * THE ONE DELIBERATE SINGLE-ACTOR PATH, and it is safe for a specific reason: pausing moves a flag
 * TOWARDS DENY and never away from it. Requiring a second approver to stop a misbehaving rollout
 * would mean the safest action is the slowest one.
 *
 * It is not, however, unaudited or ungoverned: it still requires `platform.settings.update`, MFA, a
 * fresh `platform_feature_flag_change` step-up and a mandatory reason, and it appends a history row
 * like every other transition. Turning a flag back ON always goes through maker/checker.
 */
final class PausePlatformFeatureFlag
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformFeatureFlagStateMachine $stateMachine,
    ) {}

    public function handle(PlatformFeatureFlag $flag, string $reason, User $actor): PlatformFeatureFlag
    {
        return DB::transaction(function () use ($flag, $reason, $actor): PlatformFeatureFlag {
            /** @var PlatformFeatureFlag $locked */
            $locked = PlatformFeatureFlag::query()
                ->whereKey($flag->getKey())
                ->with('targets')
                ->lockForUpdate()
                ->firstOrFail();

            $before = $locked->configuration();

            $this->stateMachine->assertCanTransition($locked->state, PlatformFeatureFlagState::Paused);

            $locked->forceFill([
                'state' => PlatformFeatureFlagState::Paused,
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();

            PlatformFeatureFlagHistory::query()->create([
                'feature_flag_id' => $locked->id,
                'change_request_id' => null,
                'action' => 'paused',
                'before_configuration' => $before,
                'after_configuration' => $locked->load('targets')->configuration(),
                'before_hash' => PlatformFeatureFlagChangeRequest::hashConfiguration($before),
                'after_hash' => PlatformFeatureFlagChangeRequest::hashConfiguration($locked->configuration()),
                'actor_user_id' => $actor->id,
                'reason' => $reason,
                'correlation_id' => (string) Str::ulid(),
            ]);

            $this->audit->record(AuditEvent::PlatformFeatureFlagPaused, $actor, null, null, $locked, [
                'flag_key' => $locked->flag_key,
                'environment' => $locked->environment,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
