<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Enums\PromotionTargetType;
use App\Domain\Billing\Models\FreePeriodOffer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deterministically resolves at most ONE free-period (trial-length) offer for a new subscription
 * (Plan §53; Phase 20C). Only `active` records whose effective window contains the anchor date (the
 * Merchant-Administrator creation instant — Gate C3) are eligible. Precedence: **merchant > plan >
 * billing_mode > global** (`all_new_merchants`), ties by latest `effective_from` then ascending target
 * `ulid` (global ties by parent `ulid`). Returns the winning offer or `null` (explicit "none").
 * Read-only; never stacks.
 */
final class ResolveFreePeriodOffer
{
    public function resolve(int $merchantId, int $planId, BillingMode $mode, ?CarbonInterface $onDate = null): ?FreePeriodOffer
    {
        $date = ($onDate ?? CarbonImmutable::now('Africa/Nairobi'))->toDateString();

        return $this->byTarget($date, PromotionTargetType::Merchant, 'merchant_id', $merchantId)
            ?? $this->byTarget($date, PromotionTargetType::Plan, 'subscription_plan_id', $planId)
            ?? $this->byTarget($date, PromotionTargetType::BillingMode, 'billing_mode', $mode->value)
            ?? $this->global($date);
    }

    private function byTarget(string $date, PromotionTargetType $type, string $column, int|string $value): ?FreePeriodOffer
    {
        $id = DB::table('free_period_offers as fpo')
            ->join('free_period_offer_targets as fpot', 'fpot.free_period_offer_id', '=', 'fpo.id')
            ->where('fpo.status', FreePeriodOfferStatus::Active->value)
            ->where('fpo.effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('fpo.effective_to')->orWhere('fpo.effective_to', '>', $date);
            })
            ->where('fpot.target_type', $type->value)
            ->where('fpot.'.$column, $value)
            ->orderByDesc('fpo.effective_from')
            ->orderBy('fpot.ulid')
            ->value('fpo.id');

        return $id === null ? null : FreePeriodOffer::query()->whereKey($id)->first();
    }

    private function global(string $date): ?FreePeriodOffer
    {
        return FreePeriodOffer::query()
            ->where('status', FreePeriodOfferStatus::Active->value)
            ->where('target_scope', PromotionTargetScope::AllNewMerchants->value)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $date);
            })
            ->orderByDesc('effective_from')
            ->orderBy('ulid')
            ->first();
    }
}
