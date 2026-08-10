<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\MerchantSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subscription as the platform sees it (COR-UI08-001 §10; Phase UI-08).
 *
 * READ-ONLY PROJECTION over Phase 20B truth. It exposes ULIDs and absolute ISO-8601 timestamps —
 * never an internal bigint id, never a merchant-client record, never a payment reference. The
 * `current_state` block explains WHY a subscription sits where it does, because a bare status on a
 * governance screen invites the wrong action.
 *
 * @mixin MerchantSubscription
 */
final class PlatformSubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pending = $this->pendingScheduledChange();

        return [
            'id' => $this->ulid,
            'merchant' => [
                'id' => $this->merchant?->ulid,
                'name' => $this->merchant?->name,
                'status' => $this->merchant?->status?->value,
                'billing_status' => $this->merchant?->billing_status?->value,
            ],
            'plan' => [
                'id' => $this->plan?->ulid,
                'key' => $this->plan?->key,
                'name' => $this->plan?->name,
            ],
            'status' => $this->status->value,
            'billing_interval' => $this->billing_interval->value,
            'trial_started_at' => $this->trial_started_at->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at->toIso8601String(),
            'trial_days_snapshot' => $this->trial_days_snapshot,
            'current_period_start' => $this->current_period_start->toIso8601String(),
            'current_period_end' => $this->current_period_end->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'expired_at' => $this->expired_at?->toIso8601String(),
            'scheduled_plan_change' => $pending === null ? null : [
                'id' => $pending->ulid,
                'effective_at' => $pending->effective_at->toIso8601String(),
            ],
            'current_state' => [
                'status' => $this->status->value,
                // The record status is NOT the request-authorization authority; merchants.billing_status
                // is (Plan §22). Saying so on the page prevents exactly the wrong inference.
                'authorization_authority' => 'merchants.billing_status',
                'explanation' => $this->explainState(),
            ],
        ];
    }

    private function explainState(): string
    {
        return match ($this->status->value) {
            'trialing' => 'In trial until '.$this->trial_ends_at->toIso8601String().'.',
            'active' => 'Active; the current period ends '.$this->current_period_end->toIso8601String().'.',
            'read_only_grace' => 'Past due and inside the configured grace window; the merchant retains read-only access.',
            'overdue' => 'Past due beyond the grace window.',
            'suspended_billing' => 'Suspended for billing. Recovery is a merchant-side payment outcome, not a platform action.',
            'cancelled' => 'Cancelled'.($this->cancelled_at === null ? '.' : ' on '.$this->cancelled_at->toIso8601String().'.'),
            // No default arm: MerchantSubscriptionStatus is a closed vocabulary, so every case is
            // named above. Adding a status without an explanation here becomes a compile-time
            // UnhandledMatchError rather than a silently unexplained state on a governance screen.
            'expired' => 'Expired'.($this->expired_at === null ? '.' : ' on '.$this->expired_at->toIso8601String().'.'),
        };
    }
}
