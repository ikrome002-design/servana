<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Services\BillingIntervalCalculator;
use App\Domain\Billing\Services\PromotionalDiscountStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Approve a DRAFT promotional discount (Plan §53; Gate C6; Phase 20C). Super-Administrator + MFA +
 * fresh step-up + mandatory reason are enforced at the route/request layer. Records `approved_by` /
 * `approved_at`; a currently-effective window goes straight to `active` (the promotion machine allows
 * `draft → active`), a future window goes to `scheduled`. After approval the financial terms and
 * targets are immutable. Row-locked; one transaction.
 */
final class ApprovePromotionalDiscount
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PromotionalDiscountStateMachine $machine,
    ) {}

    public function handle(PromotionalDiscount $discount, User $actor, string $reason): PromotionalDiscount
    {
        return DB::transaction(function () use ($discount, $actor, $reason): PromotionalDiscount {
            /** @var PromotionalDiscount $locked */
            $locked = PromotionalDiscount::query()->whereKey($discount->id)->lockForUpdate()->firstOrFail();

            $today = CarbonImmutable::now(BillingIntervalCalculator::TIMEZONE)->toDateString();
            $inWindow = $locked->effective_from->toDateString() <= $today
                && ($locked->effective_to === null || $locked->effective_to->toDateString() > $today);
            $target = $inWindow ? PromotionStatus::Active : PromotionStatus::Scheduled;

            $this->machine->ensure($locked->status, $target);

            $locked->status = $target;
            $locked->approved_by = $actor->id;
            $locked->approved_at = CarbonImmutable::now();
            $locked->change_reason = $reason;
            $locked->save();

            $context = [
                'promotion_id' => $locked->ulid,
                'to_status' => $target->value,
                'effective_from' => $locked->effective_from->toDateString(),
                'effective_to' => $locked->effective_to?->toDateString(),
                'reason' => $reason,
            ];
            $this->audit->record(AuditEvent::PromotionApproved, $actor, null, null, $locked, $context);
            if ($target === PromotionStatus::Active) {
                $this->audit->record(AuditEvent::PromotionActivated, $actor, null, null, $locked, $context);
            }

            return $locked->refresh();
        });
    }
}
