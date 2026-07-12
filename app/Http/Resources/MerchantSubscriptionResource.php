<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant subscription dashboard payload (Plan §22, §48; Phase 20B). Exposes the subscription
 * lifecycle status, the merchant BILLING status (the request-authorization authority — displayed
 * separately from operational status), trial/current-period dates, current plan + price, the
 * pending scheduled change (if any), and a server-derived `can` map. Never leaks internal ids.
 *
 * @mixin MerchantSubscription
 */
final class MerchantSubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->merchant;
        /** @var SubscriptionPlan $plan */
        $plan = $this->plan;
        /** @var SubscriptionPlanPrice $price */
        $price = $this->price;
        $pending = $this->pendingScheduledChange();

        $context = app(TenantContext::class);
        $billingReadOnly = $merchant->billingBlocksMutations();

        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'billing_status' => $merchant->billing_status->value,
            'billing_status_reason' => $merchant->billing_status_reason,
            'billing_read_only' => $billingReadOnly,
            'billing_interval' => $this->billing_interval->value,
            'trial_started_at' => $this->trial_started_at->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at->toIso8601String(),
            'current_period_start' => $this->current_period_start->toDateString(),
            'current_period_end' => $this->current_period_end->toDateString(),
            'plan' => [
                'id' => $plan->ulid,
                'key' => $plan->key,
                'name' => $plan->name,
                'tier' => $plan->tier,
            ],
            'price' => [
                'id' => $price->ulid,
                'amount_minor' => $price->amount_minor,
                'currency' => $price->currency,
                'billing_interval' => $price->billing_interval->value,
            ],
            'scheduled_plan_change' => $pending !== null ? ScheduledPlanChangeResource::make($pending) : null,
            'can' => [
                // Server-derived UX hints: a permitted plan-change is additionally blocked while
                // billing is read-only (grace/suspension). Reads/download are always permitted here.
                'schedule_plan_change' => $context->can('merchant.subscription.plan_change') && ! $billingReadOnly,
                'download_invoice' => $context->can('merchant.subscription.invoice.download'),
            ],
        ];
    }
}
