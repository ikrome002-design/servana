<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update general platform settings (the documented `settings` jsonb) by inserting a NEW
 * effective-dated version of `platform_billing_settings` (Plan §19.3 `PlatformSettingsPolicy`;
 * Phase 20A). Append-only: the billing config fields (mode/trial/grace/currency) are carried over
 * unchanged from the current version — this authority governs only the general settings map, not
 * the billing config (that is `platform.billing_settings.update`). Only documented settings keys
 * are accepted (validated upstream). Platform-governed (MFA + fresh step-up). Audits
 * `platform_settings.updated`.
 */
final class UpdatePlatformSettings
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string,mixed>  $settings
     */
    public function handle(array $settings, User $actor): PlatformBillingSettings
    {
        return DB::transaction(function () use ($settings, $actor): PlatformBillingSettings {
            $current = PlatformBillingSettings::current();

            if ($current === null) {
                throw new \RuntimeException('Platform billing settings must be seeded before general settings can be updated.');
            }

            $version = PlatformBillingSettings::query()->create([
                'billing_mode' => $current->billing_mode,
                'default_trial_days' => $current->default_trial_days,
                'grace_days' => $current->grace_days,
                'currency' => $current->currency,
                'updated_by' => $actor->id,
                'effective_from' => now(),
                'settings' => $settings,
            ]);

            $this->audit->record(AuditEvent::PlatformSettingsUpdated, $actor, null, null, $version, [
                'settings_id' => $version->ulid,
                'setting_count' => count($settings),
            ]);

            return $version;
        });
    }
}
