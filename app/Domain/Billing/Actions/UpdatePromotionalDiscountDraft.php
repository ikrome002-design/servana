<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Enums\PromotionTargetType;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Models\PromotionalDiscountTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a DRAFT promotional discount and (optionally) replace its target rows (Plan §53; Gate C6;
 * Phase 20C). Only drafts are editable — approved terms and targets are immutable (supersede with a
 * new record). Row-locked; runs in one transaction.
 *
 * @phpstan-type TargetSpec array{target_type:string,merchant_id?:int|null,subscription_plan_id?:int|null,billing_mode?:string|null}
 */
final class UpdatePromotionalDiscountDraft
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string,mixed>  $attributes
     * @param  list<TargetSpec>|null  $targets  null = leave targets unchanged
     */
    public function handle(PromotionalDiscount $discount, array $attributes, ?array $targets, User $actor): PromotionalDiscount
    {
        return DB::transaction(function () use ($discount, $attributes, $targets, $actor): PromotionalDiscount {
            /** @var PromotionalDiscount $locked */
            $locked = PromotionalDiscount::query()->whereKey($discount->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PromotionStatus::Draft) {
                throw BillingStateException::activeTermsImmutable();
            }

            $locked->fill($attributes);
            $locked->save();

            if ($targets !== null) {
                $locked->targets()->delete();
                if ($locked->target_scope !== PromotionTargetScope::AllNewMerchants) {
                    foreach ($targets as $spec) {
                        $mode = $spec['billing_mode'] ?? null;
                        $target = new PromotionalDiscountTarget;
                        $target->promotional_discount_id = $locked->id;
                        $target->target_type = PromotionTargetType::from($spec['target_type']);
                        $target->merchant_id = $spec['merchant_id'] ?? null;
                        $target->subscription_plan_id = $spec['subscription_plan_id'] ?? null;
                        $target->billing_mode = $mode === null ? null : BillingMode::from($mode);
                        $target->save();
                    }
                }
            }

            $this->audit->record(AuditEvent::PromotionDraftUpdated, $actor, null, null, $locked, [
                'promotion_id' => $locked->ulid,
                'type' => $locked->type->value,
                'value' => $locked->value,
                'currency' => $locked->currency,
                'target_scope' => $locked->target_scope->value,
                'targets_replaced' => $targets !== null,
            ]);

            return $locked->refresh();
        });
    }
}
