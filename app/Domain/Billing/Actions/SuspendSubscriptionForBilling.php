<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\MerchantBillingStatusReason;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Models\User;

/**
 * Suspend a subscription for non-payment (Plan §22, §25.2, §54; Phase 20B). active/overdue/
 * read_only_grace → suspended_billing. Reads stay allowed; mutations + new generation blocked.
 * Never changes operational `merchants.status`.
 */
final class SuspendSubscriptionForBilling
{
    public function __construct(private readonly ProjectMerchantBillingStatus $project) {}

    public function handle(MerchantSubscription $subscription, ?User $actor = null): MerchantSubscription
    {
        return $this->project->handle(
            $subscription,
            MerchantSubscriptionStatus::SuspendedBilling,
            MerchantBillingStatusReason::SuspendedOverdue,
            [AuditEvent::SubscriptionSuspendedBilling],
            $actor,
        );
    }
}
