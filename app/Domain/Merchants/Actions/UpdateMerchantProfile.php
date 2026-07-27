<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update the merchant business profile (REM-SCR-002A; Plan §27.3 Merchant Administrator
 * "merchant profile", §19.3 `merchant.profile.update`).
 *
 * The profile row is a 1:1 shell created at registration and filled by first-time setup
 * (Scope §3.2 step 2); this is the post-setup edit path that was never built. Only the
 * fields the Merchant Administrator supplies at setup are writable — `country`, `timezone`
 * defaults, `service_fee_tier`, `name`, `slug` and every billing/lifecycle column are NOT
 * this role's to change here, so they are absent from the allowlist rather than filtered
 * out later.
 *
 * Emits `merchant.profile_updated` (severity high) with the CHANGED FIELD NAMES only —
 * never the values, because the profile carries the merchant's contact of record.
 */
final class UpdateMerchantProfile
{
    /** Fields this action may ever write. Anything else is ignored by construction. */
    public const WRITABLE = [
        'business_category',
        'contact_email',
        'contact_phone',
        'receipt_display_name',
        'address',
        'town',
        'timezone',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  already validated by the Form Request
     */
    public function handle(Merchant $merchant, array $attributes, User $actor): MerchantProfile
    {
        return DB::transaction(function () use ($merchant, $attributes, $actor): MerchantProfile {
            /** @var MerchantProfile $profile */
            $profile = MerchantProfile::query()
                ->where('merchant_id', $merchant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $changed = [];

            foreach (self::WRITABLE as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue; // PATCH semantics: an absent key is "leave unchanged"
                }

                $new = $attributes[$field];
                if ($new === $profile->{$field}) {
                    continue;
                }

                $profile->{$field} = $new;
                $changed[] = $field;
            }

            if ($changed === []) {
                return $profile;
            }

            $profile->save();

            // Field NAMES only — the values are the merchant's contact of record (Plan §24.5).
            $this->audit->record(
                AuditEvent::MerchantProfileUpdated,
                $actor,
                $merchant->id,
                null,
                $profile,
                ['changed_fields' => $changed],
            );

            return $profile;
        });
    }
}
