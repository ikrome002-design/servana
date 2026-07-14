<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Services\PlatformFeeConfigurationStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a DRAFT/SCHEDULED percentage platform-fee configuration (Plan §51, §52, §13.10; Phase 20E,
 * Increment 6; `{draft, scheduled} → cancelled`). An `active` configuration is never cancelled — it is
 * superseded. Terminal, non-monetary. Platform-governed (Super-Admin; MFA + fresh step-up). An invalid
 * transition raises `422 invalid_state_transition`. Audits `platform_fee.configuration_cancelled`.
 */
final class CancelPlatformFeeConfiguration
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformFeeConfigurationStateMachine $stateMachine,
    ) {}

    public function handle(PlatformFeeConfiguration $configuration, User $actor, string $changeReason): PlatformFeeConfiguration
    {
        return DB::transaction(function () use ($configuration, $actor, $changeReason): PlatformFeeConfiguration {
            /** @var PlatformFeeConfiguration $locked */
            $locked = PlatformFeeConfiguration::query()->whereKey($configuration->id)->lockForUpdate()->firstOrFail();

            $this->stateMachine->ensure($locked->status, PlatformFeeConfigurationStatus::Cancelled);
            $locked->forceFill([
                'status' => PlatformFeeConfigurationStatus::Cancelled->value,
                'change_reason' => $changeReason,
            ])->save();

            $this->audit->record(AuditEvent::PlatformFeeConfigurationCancelled, $actor, null, null, $locked, [
                'configuration_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
