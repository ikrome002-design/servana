<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\MerchantBillingStatusReason;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Services\MerchantSubscriptionStateMachine;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The SOLE transactional subscription→merchant billing-status projection (Plan §22; Phase 20B).
 *
 * In one transaction it locks the merchant and subscription rows, guards + applies the subscription
 * transition, projects `merchants.billing_status` (Gate B2 mapping), persists the structured
 * `billing_status_reason`, and emits the typed subscription event(s) plus
 * `merchant.billing_status_changed`. If any step fails the whole thing rolls back — subscription,
 * merchant projection, reason, and audit all remain unchanged.
 *
 * Request authorization NEVER reads `merchant_subscriptions.status`; the gate reads only
 * `merchants.billing_status` ({@see Merchant::billingBlocksMutations()}). This action never mutates
 * `merchants.status` (operational) and so never clears a fraud/security/legal/compliance/manual/
 * deactivation suspension. Requires an active tenant context for the subscription's merchant
 * (bound by the request or a TenantAwareJob).
 */
final class ProjectMerchantBillingStatus
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly MerchantSubscriptionStateMachine $stateMachine,
    ) {}

    /**
     * @param  list<AuditEvent>  $events  subscription-lifecycle event(s) to emit (e.g. activated + recovered)
     * @param  array<string, mixed>  $subscriptionAttributes  extra columns to set on the subscription (e.g. cancelled_at)
     * @param  array<string, mixed>  $context  non-secret audit context
     */
    public function handle(
        MerchantSubscription $subscription,
        MerchantSubscriptionStatus $to,
        MerchantBillingStatusReason $reason,
        array $events,
        ?User $actor = null,
        array $subscriptionAttributes = [],
        array $context = [],
    ): MerchantSubscription {
        return DB::transaction(function () use ($subscription, $to, $reason, $events, $actor, $subscriptionAttributes, $context): MerchantSubscription {
            $merchant = Merchant::query()->whereKey($subscription->merchant_id)->lockForUpdate()->firstOrFail();
            $locked = MerchantSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $from = $locked->status;
            $this->stateMachine->ensure($from, $to);

            $locked->status = $to;
            foreach ($subscriptionAttributes as $column => $value) {
                $locked->setAttribute($column, $value);
            }
            $locked->save();

            $projected = $to->projectedBillingStatus();
            $merchant->billing_status = $projected;
            $merchant->billing_status_reason = $reason->value;
            $merchant->save();

            foreach ($events as $event) {
                $this->audit->record($event, $actor, $merchant->id, null, $locked, $context + [
                    'subscription_id' => $locked->ulid,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                ]);
            }

            $this->audit->record(
                AuditEvent::MerchantBillingStatusChanged,
                $actor,
                $merchant->id,
                null,
                $merchant,
                [
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'billing_status' => $projected->value,
                    'reason' => $reason->value,
                ],
            );

            return $locked->refresh();
        });
    }
}
