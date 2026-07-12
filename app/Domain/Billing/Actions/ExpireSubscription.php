<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\MerchantBillingStatusReason;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Expire a subscription (Plan §25.4; Gate B2; Phase 20B). trialing/active/overdue/read_only_grace →
 * expired (terminal), projecting merchants.billing_status → suspended_billing with reason
 * `subscription_expired`. Scheduler-driven at trial/period lapse. Idempotent per the state machine.
 */
final class ExpireSubscription
{
    public function __construct(private readonly ProjectMerchantBillingStatus $project) {}

    public function handle(MerchantSubscription $subscription, ?User $actor = null): MerchantSubscription
    {
        return $this->project->handle(
            $subscription,
            MerchantSubscriptionStatus::Expired,
            MerchantBillingStatusReason::SubscriptionExpired,
            [AuditEvent::SubscriptionExpired],
            $actor,
            ['expired_at' => CarbonImmutable::now()],
        );
    }
}
