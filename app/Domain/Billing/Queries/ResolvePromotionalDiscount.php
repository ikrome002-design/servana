<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Enums\PromotionTargetType;
use App\Domain\Billing\Models\PromotionalDiscount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deterministically resolves at most ONE promotional discount for a new subscription invoice
 * (Plan §53; Phase 20C). Only `active` records whose effective window contains the business date are
 * eligible. Precedence: **merchant > plan > billing_mode > global** (`all_new_merchants`). Within the
 * winning precedence class ties break by latest `effective_from` then ascending target `ulid`; global
 * ties break by parent `effective_from` then parent `ulid`. Returns the winning discount or `null`
 * (explicit "none"). Read-only; never stacks. Resolution is stable regardless of query ordering.
 */
final class ResolvePromotionalDiscount
{
    public function resolve(int $merchantId, int $planId, BillingMode $mode, ?CarbonInterface $onDate = null): ?PromotionalDiscount
    {
        $date = ($onDate ?? CarbonImmutable::now('Africa/Nairobi'))->toDateString();

        return $this->byTarget($date, PromotionTargetType::Merchant, 'merchant_id', $merchantId)
            ?? $this->byTarget($date, PromotionTargetType::Plan, 'subscription_plan_id', $planId)
            ?? $this->byTarget($date, PromotionTargetType::BillingMode, 'billing_mode', $mode->value)
            ?? $this->global($date);
    }

    private function byTarget(string $date, PromotionTargetType $type, string $column, int|string $value): ?PromotionalDiscount
    {
        $id = DB::table('promotional_discounts as pd')
            ->join('promotional_discount_targets as pdt', 'pdt.promotional_discount_id', '=', 'pd.id')
            ->where('pd.status', PromotionStatus::Active->value)
            ->where('pd.effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('pd.effective_to')->orWhere('pd.effective_to', '>', $date);
            })
            ->where('pdt.target_type', $type->value)
            ->where('pdt.'.$column, $value)
            ->orderByDesc('pd.effective_from')
            ->orderBy('pdt.ulid')
            ->value('pd.id');

        return $id === null ? null : PromotionalDiscount::query()->whereKey($id)->first();
    }

    private function global(string $date): ?PromotionalDiscount
    {
        return PromotionalDiscount::query()
            ->where('status', PromotionStatus::Active->value)
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
