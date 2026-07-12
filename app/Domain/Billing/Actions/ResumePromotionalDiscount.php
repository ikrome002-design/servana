<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Services\BillingIntervalCalculator;
use App\Domain\Billing\Services\PromotionalDiscountStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resume a paused promotional discount (Plan §53; Gate C6; Phase 20C). Returns it to `active` so it
 * participates in resolution again. Rejected once the effective window has ended (the lifecycle
 * scheduler expires it instead). Row-locked; mandatory reason. Audit `promotion.resumed` (high).
 */
final class ResumePromotionalDiscount
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

            $this->machine->ensure($locked->status, PromotionStatus::Active);

            $today = CarbonImmutable::now(BillingIntervalCalculator::TIMEZONE)->toDateString();
            if ($locked->effective_to !== null && $locked->effective_to->toDateString() <= $today) {
                throw BillingStateException::windowEnded();
            }

            $locked->status = PromotionStatus::Active;
            $locked->change_reason = $reason;
            $locked->save();

            $this->audit->record(AuditEvent::PromotionResumed, $actor, null, null, $locked, [
                'promotion_id' => $locked->ulid,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
