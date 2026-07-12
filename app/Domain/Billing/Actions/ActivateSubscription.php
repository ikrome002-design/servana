<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\MerchantBillingStatusReason;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Models\User;

/**
 * Activate a subscription (Plan §25.2; Phase 20B). trialing/read_only_grace/overdue → active. A
 * recovery from suspended_billing (validated payment + billing-only reason, 20D-W) additionally
 * emits `subscription.recovered`. Transactional projection via {@see ProjectMerchantBillingStatus}.
 */
final class ActivateSubscription
{
    public function __construct(private readonly ProjectMerchantBillingStatus $project) {}

    public function handle(MerchantSubscription $subscription, ?User $actor = null): MerchantSubscription
    {
        $recovery = $subscription->status === MerchantSubscriptionStatus::SuspendedBilling;

        return $this->project->handle(
            $subscription,
            MerchantSubscriptionStatus::Active,
            $recovery ? MerchantBillingStatusReason::RecoveredPayment : MerchantBillingStatusReason::Activated,
            $recovery
                ? [AuditEvent::SubscriptionActivated, AuditEvent::SubscriptionRecovered]
                : [AuditEvent::SubscriptionActivated],
            $actor,
        );
    }
}
