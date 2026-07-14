<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Exceptions\BillingOverlapException;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Services\PlatformFeeConfigurationStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Supersede an ACTIVE percentage platform-fee configuration with a new version (Plan §51, §52, §13.10;
 * Phase 20E, Increment 6). Approved monetary terms are immutable — a change NEVER edits the active row
 * in place; the current active configuration transitions `active → superseded` and a NEW successor is
 * created (scheduled/active per its `effective_from`). The current row is superseded FIRST so the
 * successor does not collide with it under the active/scheduled exclusion. Platform-governed
 * (Super-Admin; MFA + fresh step-up). Audits `platform_fee.configuration_superseded`.
 */
final class SupersedePlatformFeeConfiguration
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformFeeConfigurationStateMachine $stateMachine,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(PlatformFeeConfiguration $current, array $data, User $actor): PlatformFeeConfiguration
    {
        return DB::transaction(function () use ($current, $data, $actor): PlatformFeeConfiguration {
            /** @var PlatformFeeConfiguration $locked */
            $locked = PlatformFeeConfiguration::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();

            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32($locked->billing_mode->value.':'.$locked->currency)]);

            $this->stateMachine->ensure($locked->status, PlatformFeeConfigurationStatus::Superseded);
            $locked->forceFill(['status' => PlatformFeeConfigurationStatus::Superseded->value])->save();

            $target = CarbonImmutable::parse((string) $data['effective_from'], 'Africa/Nairobi')->startOfDay()->isAfter(CarbonImmutable::now('Africa/Nairobi')->startOfDay())
                ? PlatformFeeConfigurationStatus::Scheduled
                : PlatformFeeConfigurationStatus::Active;

            try {
                $successor = PlatformFeeConfiguration::query()->create([
                    'billing_mode' => BillingMode::from((string) $data['billing_mode']),
                    'percentage_basis_points' => isset($data['percentage_basis_points']) ? (int) $data['percentage_basis_points'] : null,
                    'fixed_component_minor' => isset($data['fixed_component_minor']) ? (int) $data['fixed_component_minor'] : null,
                    'tier_behavior' => isset($data['tier_behavior']) ? CanonicalPlatformFeeTier::from((string) $data['tier_behavior']) : null,
                    'shared_split_basis_points' => isset($data['shared_split_basis_points']) ? (int) $data['shared_split_basis_points'] : null,
                    'fee_basis_type' => isset($data['fee_basis_type']) ? PlatformFeeBasisType::from((string) $data['fee_basis_type']) : null,
                    'currency' => strtoupper((string) $data['currency']),
                    'effective_from' => (string) $data['effective_from'],
                    'effective_to' => isset($data['effective_to']) ? (string) $data['effective_to'] : null,
                    'status' => $target,
                    'created_by' => $actor->id,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'change_reason' => (string) $data['change_reason'],
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23P01') {
                    throw BillingOverlapException::platformFeeConfiguration();
                }
                throw $e;
            }

            $this->audit->record(AuditEvent::PlatformFeeConfigurationSuperseded, $actor, null, null, $successor, [
                'superseded_configuration_id' => $locked->ulid,
                'successor_configuration_id' => $successor->ulid,
                'new_state' => $target->value,
            ]);

            return $successor;
        });
    }
}
