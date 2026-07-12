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
 * Pause an active free-period offer (Plan §53; Gate C6; Phase 20C). Availability only — a paused offer
 * is excluded from resolution; the configured days are unchanged and existing trials are untouched.
 * Row-locked; mandatory reason. Audit `free_period_offer.paused` (high).
 */
final class PauseFreePeriodOffer
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

            $this->machine->ensure($locked->status, FreePeriodOfferStatus::Paused);

            $locked->status = FreePeriodOfferStatus::Paused;
            $locked->change_reason = $reason;
            $locked->save();

            $this->audit->record(AuditEvent::FreePeriodOfferPaused, $actor, null, null, $locked, [
                'free_period_offer_id' => $locked->ulid,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
