<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\MerchantBillingStatusReason;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Models\User;

/**
 * Mark a subscription overdue (Plan §25.2, §54; Phase 20B). active → overdue.
 */
final class MarkSubscriptionOverdue
{
    public function __construct(private readonly ProjectMerchantBillingStatus $project) {}

    public function handle(MerchantSubscription $subscription, ?User $actor = null): MerchantSubscription
    {
        return $this->project->handle(
            $subscription,
            MerchantSubscriptionStatus::Overdue,
            MerchantBillingStatusReason::Overdue,
            [AuditEvent::SubscriptionOverdue],
            $actor,
        );
    }
}
