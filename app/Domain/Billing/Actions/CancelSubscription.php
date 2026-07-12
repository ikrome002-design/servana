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
 * Cancel a subscription (Plan §25.4; Gate B2; Phase 20B). Any non-terminal → cancelled (terminal),
 * projecting merchants.billing_status → suspended_billing with reason `subscription_cancelled`.
 *
 * Gate B2: the projection happens only when cancellation becomes EFFECTIVE. A cancellation scheduled
 * for a future boundary (`$effectiveAt` in the future) does NOT suspend the merchant early — it
 * returns the subscription unchanged; the scheduler finalizes it at the boundary. Immediate
 * cancellation (default) projects now.
 */
final class CancelSubscription
{
    public function __construct(private readonly ProjectMerchantBillingStatus $project) {}

    public function handle(MerchantSubscription $subscription, ?User $actor = null, ?CarbonImmutable $effectiveAt = null): MerchantSubscription
    {
        if ($effectiveAt !== null && $effectiveAt->isFuture()) {
            // Scheduled end-of-period cancellation — do not project early (B2). The scheduler
            // invokes this action again (immediate) once the effective boundary is reached.
            return $subscription;
        }

        return $this->project->handle(
            $subscription,
            MerchantSubscriptionStatus::Cancelled,
            MerchantBillingStatusReason::SubscriptionCancelled,
            [AuditEvent::SubscriptionCancelled],
            $actor,
            ['cancelled_at' => CarbonImmutable::now()],
        );
    }
}
