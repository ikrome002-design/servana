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
 * Deactivate a merchant operationally (Plan §22, §24.1; Phase 20B). Super-Admin platform governance:
 * `merchants.status` active | suspended → deactivated (terminal). Mandatory reason. Locks the row,
 * validates the transition, and mutates ONLY the operational lifecycle columns — it NEVER touches
 * `merchants.billing_status` and NEVER creates a subscription or payment row. Emits exactly one typed
 * `merchant.deactivated` audit event (Critical) on the platform/governance chain, with a redacted
 * context.
 */
final class DeactivateMerchant
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Merchant $merchant, string $reason, User $actor): Merchant
    {
        return DB::transaction(function () use ($merchant, $reason, $actor): Merchant {
            $locked = Merchant::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            $from = $locked->status;
            if (! $from->canTransitionTo(MerchantStatus::Deactivated)) {
                throw MerchantStatusException::invalidTransition($from->value, MerchantStatus::Deactivated->value);
            }

            $locked->status = MerchantStatus::Deactivated;
            $locked->deactivated_at = now();
            $locked->suspension_reason = $reason;
            $locked->save();

            $this->audit->record(AuditEvent::MerchantDeactivated, $actor, null, null, $locked, [
                'merchant_id' => $locked->ulid,
                'from_status' => $from->value,
                'to_status' => MerchantStatus::Deactivated->value,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }
}
