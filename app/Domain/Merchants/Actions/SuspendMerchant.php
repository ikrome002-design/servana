<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Integrations\ReferEarn\Actions\EnqueueProductEvent;
use App\Domain\Integrations\ReferEarn\Enums\MerchantStatusReasonCategory;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Exceptions\MerchantStatusException;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Suspend a merchant operationally (Plan §22, §24.1; Phase 20B). Super-Admin platform governance:
 * `merchants.status` active → suspended. Mandatory reason. Locks the row, validates the transition,
 * and mutates ONLY the operational lifecycle columns — it NEVER touches `merchants.billing_status`
 * (a separate authority) and NEVER creates a subscription or payment row. Emits exactly one typed
 * `merchant.suspended` audit event on the platform/governance chain (null merchant chain), with a
 * redacted context (merchant ULID + prev/new status + sanitised reason).
 */
final class SuspendMerchant
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly EnqueueProductEvent $enqueueProductEvent,
    ) {}

    /**
     * @param  MerchantStatusReasonCategory  $reasonCategory  Phase 21R-A (Plan §58B.1): the BOUNDED
     *                                                        category that crosses the Citrus R&E boundary. The free-text `$reason` never does.
     *                                                        No as-built governance request supplies a category yet, so it defaults to `manual` —
     *                                                        the conservative reading, since Servana must not infer a category from operator prose.
     */
    public function handle(
        Merchant $merchant,
        string $reason,
        User $actor,
        MerchantStatusReasonCategory $reasonCategory = MerchantStatusReasonCategory::Manual,
    ): Merchant {
        return DB::transaction(function () use ($merchant, $reason, $actor, $reasonCategory): Merchant {
            $locked = Merchant::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            $from = $locked->status;
            if (! $from->canTransitionTo(MerchantStatus::Suspended)) {
                throw MerchantStatusException::invalidTransition($from->value, MerchantStatus::Suspended->value);
            }

            $locked->status = MerchantStatus::Suspended;
            $locked->suspended_at = now();
            $locked->suspension_reason = $reason;
            $locked->save();

            $this->audit->record(AuditEvent::MerchantSuspended, $actor, null, null, $locked, [
                'merchant_id' => $locked->ulid,
                'from_status' => $from->value,
                'to_status' => MerchantStatus::Suspended->value,
                'reason' => $reason,
            ]);

            // Phase 21R-A (Plan §58B.1, §58A.2). Same transaction as the status change, so the
            // fact and its event are inseparable. Category only — never the free-text reason.
            // The emission-scope gate inside the action suppresses everything for a merchant
            // with no live referral claim.
            $this->enqueueProductEvent->handle(
                ReOutboundEventType::MerchantStatusChanged,
                $locked,
                ['previous_status' => $from->value, 'reason_category' => $reasonCategory],
            );

            return $locked;
        });
    }
}
