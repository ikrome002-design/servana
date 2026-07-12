<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Exceptions\MerchantStatusException;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reactivate an operationally-suspended merchant (Plan §22, §24.1; Phase 20B). Super-Admin platform
 * governance: `merchants.status` suspended → active. Mandatory reason. Locks the row, validates the
 * transition, and mutates ONLY the operational lifecycle columns.
 *
 * It NEVER touches `merchants.billing_status`: operational reactivation is NOT a billing-recovery
 * path (a billing suspension is cleared only by the billing lifecycle, never here). It never creates
 * a subscription or payment row. Emits exactly one typed `merchant.reactivated` audit event on the
 * platform/governance chain, with a redacted context.
 */
final class ReactivateMerchant
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Merchant $merchant, string $reason, User $actor): Merchant
    {
        return DB::transaction(function () use ($merchant, $reason, $actor): Merchant {
            $locked = Merchant::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            $from = $locked->status;
            if (! $from->canTransitionTo(MerchantStatus::Active)) {
                throw MerchantStatusException::invalidTransition($from->value, MerchantStatus::Active->value);
            }

            $locked->status = MerchantStatus::Active;
            $locked->suspended_at = null;
            $locked->suspension_reason = null;
            $locked->save();

            $this->audit->record(AuditEvent::MerchantReactivated, $actor, null, null, $locked, [
                'merchant_id' => $locked->ulid,
                'from_status' => $from->value,
                'to_status' => MerchantStatus::Active->value,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }
}
