<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Services\FreePeriodOfferStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a free-period offer before it takes effect (Plan §53; Gate C6; Phase 20C). Allowed only from
 * `draft` or `scheduled` (the state machine enforces this). Terminal; existing trials are never
 * changed. Row-locked; mandatory reason. Audit `free_period_offer.cancelled` (high).
 */
final class CancelFreePeriodOffer
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly FreePeriodOfferStateMachine $machine,
    ) {}

    public function handle(FreePeriodOffer $offer, User $actor, string $reason): FreePeriodOffer
    {
        return DB::transaction(function () use ($offer, $actor, $reason): FreePeriodOffer {
            /** @var FreePeriodOffer $locked */
            $locked = FreePeriodOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, FreePeriodOfferStatus::Cancelled);

            $locked->status = FreePeriodOfferStatus::Cancelled;
            $locked->change_reason = $reason;
            $locked->save();

            $this->audit->record(AuditEvent::FreePeriodOfferCancelled, $actor, null, null, $locked, [
                'free_period_offer_id' => $locked->ulid,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
