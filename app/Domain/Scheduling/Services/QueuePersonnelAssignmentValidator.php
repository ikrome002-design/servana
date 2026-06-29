<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Exceptions\SchedulingValidationException;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\ValueObjects\SchedulingDecision;
use Carbon\CarbonImmutable;

/**
 * Personnel eligibility/availability gate for queue assignment (Plan §37;
 * Phase 16B). It does NOT duplicate eligibility/availability logic — it delegates
 * to the shared Phase 15B {@see PersonnelSchedulingValidator} for the "now + service
 * duration" window, then adds the queue-specific conflict check (the candidate must
 * not already be actively serving another client — an `in_service` entry elsewhere).
 *
 * Revalidation runs on assign, transfer, call, and start.
 */
final class QueuePersonnelAssignmentValidator
{
    public const CODE_BUSY = 'personnel_unavailable';

    public function __construct(private readonly PersonnelSchedulingValidator $scheduling) {}

    public function validate(QueueEntry $entry, StaffProfile $staff): SchedulingDecision
    {
        /** @var Service $service */
        $service = $entry->service()->firstOrFail();
        /** @var Merchant $merchant */
        $merchant = $entry->merchant()->firstOrFail();
        $branch = $entry->branch()->firstOrFail();

        $tz = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');
        $start = CarbonImmutable::now($tz);
        $end = $start->addMinutes(max(1, (int) $service->duration_minutes));

        $decision = $this->scheduling->validate($merchant, $branch, $service, $staff, $start, $end);
        if (! $decision->allowed) {
            return $decision;
        }

        // Queue conflict: the candidate must not already be actively serving another
        // client (an in_service entry elsewhere) in this merchant.
        $busy = QueueEntry::query()
            ->where('merchant_id', $entry->merchant_id)
            ->where('staff_profile_id', $staff->id)
            ->where('status', QueueEntryStatus::InService->value)
            ->whereKeyNot($entry->id)
            ->exists();

        if ($busy) {
            return SchedulingDecision::deny(self::CODE_BUSY, 'Personnel is currently serving another client.');
        }

        return SchedulingDecision::pass();
    }

    /** @throws SchedulingValidationException */
    public function ensure(QueueEntry $entry, StaffProfile $staff): void
    {
        $decision = $this->validate($entry, $staff);

        if (! $decision->allowed) {
            throw SchedulingValidationException::fromDecision($decision);
        }
    }

    /** Whether the candidate currently passes (used by the selector). */
    public function isAssignable(QueueEntry $entry, StaffProfile $staff): bool
    {
        return $this->validate($entry, $staff)->allowed;
    }
}
