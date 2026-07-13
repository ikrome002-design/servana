<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a DRAFT percentage platform-fee configuration in place (Plan §51, §52; Phase 20E, Increment 6).
 * Only a `draft` may be edited — once approved, monetary terms are immutable and a change is a
 * SUPERSEDE (new version), never an in-place edit (fails closed on a non-draft). Platform-governed
 * (Super-Admin; MFA + fresh step-up). Audits `platform_fee.configuration_updated`.
 */
final class UpdatePlatformFeeConfigurationDraft
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(PlatformFeeConfiguration $configuration, array $data, User $actor): PlatformFeeConfiguration
    {
        return DB::transaction(function () use ($configuration, $data, $actor): PlatformFeeConfiguration {
            /** @var PlatformFeeConfiguration $locked */
            $locked = PlatformFeeConfiguration::query()->whereKey($configuration->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PlatformFeeConfigurationStatus::Draft) {
                // Approved terms are immutable — only a draft may be edited (supersede otherwise).
                throw PlatformFeeException::notEditable($locked->status->value);
            }

            $locked->forceFill([
                'billing_mode' => BillingMode::from((string) $data['billing_mode'])->value,
                'percentage_basis_points' => isset($data['percentage_basis_points']) ? (int) $data['percentage_basis_points'] : null,
                'fixed_component_minor' => isset($data['fixed_component_minor']) ? (int) $data['fixed_component_minor'] : null,
                'tier_behavior' => isset($data['tier_behavior']) ? CanonicalPlatformFeeTier::from((string) $data['tier_behavior'])->value : null,
                'shared_split_basis_points' => isset($data['shared_split_basis_points']) ? (int) $data['shared_split_basis_points'] : null,
                'fee_basis_type' => isset($data['fee_basis_type']) ? PlatformFeeBasisType::from((string) $data['fee_basis_type'])->value : null,
                'currency' => strtoupper((string) $data['currency']),
                'effective_from' => (string) $data['effective_from'],
                'effective_to' => isset($data['effective_to']) ? (string) $data['effective_to'] : null,
                'change_reason' => (string) $data['change_reason'],
            ])->save();

            $this->audit->record(AuditEvent::PlatformFeeConfigurationUpdated, $actor, null, null, $locked, [
                'configuration_id' => $locked->ulid,
                'billing_mode' => $locked->billing_mode->value,
                'currency' => $locked->currency,
            ]);

            return $locked;
        });
    }
}
