<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update platform billing configuration by inserting a NEW effective-dated version (Plan §13.9,
 * §47, §50; Phase 20A). Append-only: prior versions are never mutated. Owns the billing config
 * fields (mode/trial/grace/currency); the general `settings` jsonb is carried over from the
 * current version unless supplied. Platform-governed (MFA + fresh step-up). Audits
 * `platform_billing.settings_updated`.
 */
final class UpdatePlatformBillingSettings
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{billing_mode:string,default_trial_days:int,grace_days:int,currency:string,settings?:array<string,mixed>}  $data
     */
    public function handle(array $data, User $actor): PlatformBillingSettings
    {
        return DB::transaction(function () use ($data, $actor): PlatformBillingSettings {
            $current = PlatformBillingSettings::current();

            $version = PlatformBillingSettings::query()->create([
                'billing_mode' => BillingMode::from($data['billing_mode']),
                'default_trial_days' => $data['default_trial_days'],
                'grace_days' => $data['grace_days'],
                'currency' => $data['currency'],
                'updated_by' => $actor->id,
                'effective_from' => now(),
                'settings' => $data['settings'] ?? ($current !== null ? $current->settings : []),
            ]);

            $this->audit->record(AuditEvent::PlatformBillingSettingsUpdated, $actor, null, null, $version, [
                'settings_id' => $version->ulid,
                'billing_mode' => $version->billing_mode->value,
                'default_trial_days' => $version->default_trial_days,
                'grace_days' => $version->grace_days,
                'currency' => $version->currency,
            ]);

            return $version;
        });
    }
}
