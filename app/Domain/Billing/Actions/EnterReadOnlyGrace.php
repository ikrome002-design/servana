<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\MerchantBillingStatusReason;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Models\User;

/**
 * Enter the temporary read-only grace window (Plan §22, §25.2; Phase 20B). trialing/active →
 * read_only_grace. Reads stay allowed; mutations and new export/report/PDF generation are blocked.
 */
final class EnterReadOnlyGrace
{
    public function __construct(private readonly ProjectMerchantBillingStatus $project) {}

    public function handle(MerchantSubscription $subscription, ?User $actor = null): MerchantSubscription
    {
        return $this->project->handle(
            $subscription,
            MerchantSubscriptionStatus::ReadOnlyGrace,
            MerchantBillingStatusReason::GraceEntered,
            [AuditEvent::SubscriptionReadOnlyGraceEntered],
            $actor,
        );
    }
}
