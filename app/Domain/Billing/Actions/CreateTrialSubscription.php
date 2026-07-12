<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantBillingStatusReason;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Queries\ResolveEffectivePlatformBillingSettings;
use App\Domain\Billing\Services\BillingIntervalCalculator;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Create a merchant's trial subscription during first-time setup (Plan §22, §48; Gate B1; Phase 20B).
 *
 * The trial anchor (`trial_started_at`) is the ORIGINAL founding Merchant-Administrator membership's
 * creation time — never setup-completion time. `trial_days_snapshot` snapshots the effective platform
 * `default_trial_days` once and is never rewritten by later settings changes. The captured plan/price
 * fix the `billing_interval`. On creation the merchant billing projection becomes `trialing`
 * transactionally.
 *
 * Idempotent: if the merchant already has a current non-terminal subscription, that subscription is
 * returned unchanged (no duplicate). The DB partial-unique index is the concurrency backstop.
 * Requires an active tenant context for the merchant.
 */
final class CreateTrialSubscription
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly BillingIntervalCalculator $calculator,
        private readonly ResolveEffectivePlatformBillingSettings $settings,
    ) {}

    public function handle(Merchant $merchant, SubscriptionPlanPrice $price, ?User $actor = null): MerchantSubscription
    {
        return DB::transaction(function () use ($merchant, $price, $actor): MerchantSubscription {
            Merchant::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            $existing = MerchantSubscription::query()
                ->where('merchant_id', $merchant->id)
                ->whereIn('status', MerchantSubscriptionStatus::nonTerminalValues())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing; // idempotent — no duplicate current subscription
            }

            $anchor = $this->trialAnchor($merchant); // raw instant (not tz-shifted)
            $effectiveSettings = $this->settings->current();
            $trialDays = $effectiveSettings === null ? 0 : $effectiveSettings->default_trial_days;
            $trialEnds = $this->calculator->trialEnd($anchor, $trialDays);

            // Period boundaries are DATE columns → use the Nairobi calendar date of the anchor.
            $periodStart = $this->calculator->nairobiDate($anchor);
            $periodEnd = $this->calculator->nextBoundary($periodStart, $price->billing_interval);

            $subscription = new MerchantSubscription;
            $subscription->merchant_id = $merchant->id;
            $subscription->plan_id = $price->plan_id;
            $subscription->price_id = $price->id;
            $subscription->status = MerchantSubscriptionStatus::Trialing;
            $subscription->billing_interval = $price->billing_interval;
            $subscription->trial_days_snapshot = $trialDays;
            $subscription->trial_started_at = $anchor;
            $subscription->trial_ends_at = $trialEnds;
            $subscription->current_period_start = $periodStart;
            $subscription->current_period_end = $periodEnd;
            $subscription->save();

            $merchant->billing_status = MerchantBillingStatus::Trialing;
            $merchant->billing_status_reason = MerchantBillingStatusReason::TrialStarted->value;
            $merchant->save();

            $context = [
                'subscription_id' => $subscription->ulid,
                'plan_id' => $subscription->plan_id,
                'price_id' => $subscription->price_id,
                'trial_days_snapshot' => $trialDays,
            ];
            $this->audit->record(AuditEvent::SubscriptionCreated, $actor, $merchant->id, null, $subscription, $context);
            $this->audit->record(AuditEvent::SubscriptionTrialStarted, $actor, $merchant->id, null, $subscription, $context);
            $this->audit->record(AuditEvent::MerchantBillingStatusChanged, $actor, $merchant->id, null, $merchant, [
                'from_status' => null,
                'to_status' => MerchantSubscriptionStatus::Trialing->value,
                'billing_status' => MerchantBillingStatus::Trialing->value,
                'reason' => MerchantBillingStatusReason::TrialStarted->value,
            ]);

            return $subscription;
        });
    }

    /**
     * Gate B1 trial anchor — the founding (earliest) Merchant-Administrator membership's creation
     * time. Falls back to the merchant's own creation time when no admin membership is found.
     */
    private function trialAnchor(Merchant $merchant): CarbonImmutable
    {
        $foundingAdmin = MerchantUser::query()
            ->where('merchant_id', $merchant->id)
            ->where('role', MerchantUserRole::MerchantAdmin)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $anchor = $foundingAdmin !== null ? $foundingAdmin->created_at : $merchant->created_at;

        // Raw instant — never tz-shifted, so the stored trial_started_at exactly equals the
        // founding membership's creation instant (Gate B1). Nairobi is applied only for date math.
        return CarbonImmutable::parse($anchor ?? CarbonImmutable::now());
    }
}
