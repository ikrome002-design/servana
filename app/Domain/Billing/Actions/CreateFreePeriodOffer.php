<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Enums\PromotionTargetType;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Models\FreePeriodOfferTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a draft free-period offer with its explicit normalized target rows (Plan §53; Phase 20C).
 * Platform action. Always starts in `draft`; targets created only for a non-global scope. One
 * transaction.
 *
 * @phpstan-type TargetSpec array{target_type:string,merchant_id?:int|null,subscription_plan_id?:int|null,billing_mode?:string|null}
 */
final class CreateFreePeriodOffer
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string,mixed>  $attributes  name/free_period_days/target_scope/effective_from/effective_to
     * @param  list<TargetSpec>  $targets
     */
    public function handle(array $attributes, array $targets, User $actor): FreePeriodOffer
    {
        return DB::transaction(function () use ($attributes, $targets, $actor): FreePeriodOffer {
            $offer = new FreePeriodOffer;
            $offer->fill($attributes);
            $offer->status = FreePeriodOfferStatus::Draft;
            $offer->created_by = $actor->id;
            $offer->approved_by = null;
            $offer->approved_at = null;
            $offer->save();

            $targetUlids = [];
            if ($offer->target_scope !== PromotionTargetScope::AllNewMerchants) {
                foreach ($targets as $spec) {
                    $mode = $spec['billing_mode'] ?? null;
                    $target = new FreePeriodOfferTarget;
                    $target->free_period_offer_id = $offer->id;
                    $target->target_type = PromotionTargetType::from($spec['target_type']);
                    $target->merchant_id = $spec['merchant_id'] ?? null;
                    $target->subscription_plan_id = $spec['subscription_plan_id'] ?? null;
                    $target->billing_mode = $mode === null ? null : BillingMode::from($mode);
                    $target->save();
                    $targetUlids[] = $target->ulid;
                }
            }

            $this->audit->record(AuditEvent::FreePeriodOfferCreated, $actor, null, null, $offer, [
                'free_period_offer_id' => $offer->ulid,
                'free_period_days' => $offer->free_period_days,
                'target_scope' => $offer->target_scope->value,
                'effective_from' => $offer->effective_from->toDateString(),
                'effective_to' => $offer->effective_to?->toDateString(),
                'target_ulids' => $targetUlids,
            ]);

            return $offer->refresh();
        });
    }
}
