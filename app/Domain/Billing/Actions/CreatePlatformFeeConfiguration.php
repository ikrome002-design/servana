<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a percentage platform-fee configuration as a DRAFT (Plan §51, §52; Phase 20E, Increment 6).
 * Platform-governed (Super-Admin; MFA + fresh BillingConfiguration step-up at the route). Value-shape
 * and coherence invariants are enforced by the Form Request + the DB CHECKs; a draft does not yet
 * participate in the active/scheduled effective-window exclusion. Audits `platform_fee.configuration_created`.
 */
final class CreatePlatformFeeConfiguration
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): PlatformFeeConfiguration
    {
        return DB::transaction(function () use ($data, $actor): PlatformFeeConfiguration {
            $config = PlatformFeeConfiguration::query()->create([
                'billing_mode' => BillingMode::from((string) $data['billing_mode']),
                'percentage_basis_points' => isset($data['percentage_basis_points']) ? (int) $data['percentage_basis_points'] : null,
                'fixed_component_minor' => isset($data['fixed_component_minor']) ? (int) $data['fixed_component_minor'] : null,
                'tier_behavior' => isset($data['tier_behavior']) ? CanonicalPlatformFeeTier::from((string) $data['tier_behavior']) : null,
                'shared_split_basis_points' => isset($data['shared_split_basis_points']) ? (int) $data['shared_split_basis_points'] : null,
                'fee_basis_type' => isset($data['fee_basis_type']) ? PlatformFeeBasisType::from((string) $data['fee_basis_type']) : null,
                'currency' => strtoupper((string) $data['currency']),
                'effective_from' => (string) $data['effective_from'],
                'effective_to' => isset($data['effective_to']) ? (string) $data['effective_to'] : null,
                'status' => PlatformFeeConfigurationStatus::Draft,
                'created_by' => $actor->id,
                'change_reason' => (string) $data['change_reason'],
            ]);

            $this->audit->record(AuditEvent::PlatformFeeConfigurationCreated, $actor, null, null, $config, [
                'configuration_id' => $config->ulid,
                'billing_mode' => $config->billing_mode->value,
                'currency' => $config->currency,
                'percentage_basis_points' => $config->percentage_basis_points,
                'tier_behavior' => $config->tier_behavior?->value,
            ]);

            return $config;
        });
    }
}
