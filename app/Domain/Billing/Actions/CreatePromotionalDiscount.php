<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Enums\PromotionTargetType;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Models\PromotionalDiscountTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a draft promotional discount with its explicit normalized target rows (Plan §53; Phase 20C).
 * Platform action — Super-Administrator governed. Always starts in `draft`; targets are created only
 * for a non-global scope (`all_new_merchants` has none). Runs in one transaction.
 *
 * @phpstan-type TargetSpec array{target_type:string,merchant_id?:int|null,subscription_plan_id?:int|null,billing_mode?:string|null}
 */
final class CreatePromotionalDiscount
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string,mixed>  $attributes  name/type/value/currency/target_scope/effective_from/effective_to
     * @param  list<TargetSpec>  $targets
     */
    public function handle(array $attributes, array $targets, User $actor): PromotionalDiscount
    {
        return DB::transaction(function () use ($attributes, $targets, $actor): PromotionalDiscount {
            $discount = new PromotionalDiscount;
            $discount->fill($attributes);
            $discount->status = PromotionStatus::Draft;
            $discount->created_by = $actor->id;
            $discount->approved_by = null;
            $discount->approved_at = null;
            $discount->save();

            $targetUlids = [];
            if ($discount->target_scope !== PromotionTargetScope::AllNewMerchants) {
                foreach ($targets as $spec) {
                    $mode = $spec['billing_mode'] ?? null;
                    $target = new PromotionalDiscountTarget;
                    $target->promotional_discount_id = $discount->id;
                    $target->target_type = PromotionTargetType::from($spec['target_type']);
                    $target->merchant_id = $spec['merchant_id'] ?? null;
                    $target->subscription_plan_id = $spec['subscription_plan_id'] ?? null;
                    $target->billing_mode = $mode === null ? null : BillingMode::from($mode);
                    $target->save();
                    $targetUlids[] = $target->ulid;
                }
            }

            $this->audit->record(AuditEvent::PromotionCreated, $actor, null, null, $discount, [
                'promotion_id' => $discount->ulid,
                'type' => $discount->type->value,
                'value' => $discount->value,
                'currency' => $discount->currency,
                'target_scope' => $discount->target_scope->value,
                'effective_from' => $discount->effective_from->toDateString(),
                'effective_to' => $discount->effective_to?->toDateString(),
                'target_ulids' => $targetUlids,
            ]);

            return $discount->refresh();
        });
    }
}
