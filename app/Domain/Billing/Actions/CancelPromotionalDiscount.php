<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Services\PromotionalDiscountStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a promotional discount before it takes effect (Plan §53; Gate C6; Phase 20C). Allowed only
 * from `draft` or `scheduled` (never from `active`/`paused`/terminal — the state machine enforces
 * this). Terminal. Row-locked; mandatory reason. Audit `promotion.cancelled` (high).
 */
final class CancelPromotionalDiscount
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

            $this->machine->ensure($locked->status, PromotionStatus::Cancelled);

            $locked->status = PromotionStatus::Cancelled;
            $locked->change_reason = $reason;
            $locked->save();

            $this->audit->record(AuditEvent::PromotionCancelled, $actor, null, null, $locked, [
                'promotion_id' => $locked->ulid,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
