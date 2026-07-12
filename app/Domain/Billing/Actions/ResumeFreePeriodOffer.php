<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Services\BillingIntervalCalculator;
use App\Domain\Billing\Services\FreePeriodOfferStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resume a paused free-period offer (Plan §53; Gate C6; Phase 20C). Returns it to `active`. Rejected
 * once the effective window has ended (the lifecycle scheduler expires it instead). Row-locked;
 * mandatory reason. Audit `free_period_offer.resumed` (high).
 */
final class ResumeFreePeriodOffer
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

            $this->machine->ensure($locked->status, FreePeriodOfferStatus::Active);

            $today = CarbonImmutable::now(BillingIntervalCalculator::TIMEZONE)->toDateString();
            if ($locked->effective_to !== null && $locked->effective_to->toDateString() <= $today) {
                throw BillingStateException::windowEnded();
            }

            $locked->status = FreePeriodOfferStatus::Active;
            $locked->change_reason = $reason;
            $locked->save();

            $this->audit->record(AuditEvent::FreePeriodOfferResumed, $actor, null, null, $locked, [
                'free_period_offer_id' => $locked->ulid,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
