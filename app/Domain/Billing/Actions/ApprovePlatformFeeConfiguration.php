<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Exceptions\BillingOverlapException;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Services\PlatformFeeConfigurationStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Approve a DRAFT/SCHEDULED percentage platform-fee configuration (Plan §51, §52; Phase 20E,
 * Increment 6). The target state is derived server-side from `effective_from`: a future window →
 * `scheduled`; an already-effective window → `active`. Stamps approval metadata. The active/scheduled
 * effective-window exclusion is DB-authoritative; a violation surfaces a friendly 409. Platform-governed
 * (Super-Admin; MFA + fresh step-up). Audits `platform_fee.configuration_approved`.
 */
final class ApprovePlatformFeeConfiguration
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

            // Serialize approvals on the (billing_mode, currency) applicability so two concurrent
            // approvals cannot both pass the effective-window exclusion check.
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32($locked->billing_mode->value.':'.$locked->currency)]);

            $target = CarbonImmutable::parse((string) $locked->effective_from, 'Africa/Nairobi')->startOfDay()->isAfter(CarbonImmutable::now('Africa/Nairobi')->startOfDay())
                ? PlatformFeeConfigurationStatus::Scheduled
                : PlatformFeeConfigurationStatus::Active;

            $this->stateMachine->ensure($locked->status, $target);

            try {
                $locked->forceFill([
                    'status' => $target->value,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'change_reason' => $changeReason,
                ])->save();
            } catch (QueryException $e) {
                if ($e->getCode() === '23P01') {
                    throw BillingOverlapException::platformFeeConfiguration();
                }
                throw $e;
            }

            $this->audit->record(AuditEvent::PlatformFeeConfigurationApproved, $actor, null, null, $locked, [
                'configuration_id' => $locked->ulid,
                'new_state' => $target->value,
                'billing_mode' => $locked->billing_mode->value,
                'currency' => $locked->currency,
            ]);

            return $locked;
        });
    }
}
