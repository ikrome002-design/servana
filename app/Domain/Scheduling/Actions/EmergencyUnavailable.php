<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\AvailabilityType;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\ValueObjects\TimeInterval;
use App\Models\User;
use App\Support\Redaction\Redactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR-only emergency unavailability (Plan §13.7, §80 Phase 15B). Creates (or
 * replaces) a date-specific UNAVAILABLE exception interval covering the selected
 * remaining schedule, taking immediate effect in AvailabilityResolver.
 *
 * It does NOT create/edit/transfer/cancel appointments or queue entries — those
 * aggregates do not exist yet (reassignment effects: appointments → Phase 16A,
 * queues → Phase 16B). HR authority + same-branch scope are enforced upstream.
 * Transactional; one coherent audit event with a SANITISED change reason.
 */
final class EmergencyUnavailable
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Redactor $redactor,
    ) {}

    /**
     * @return Collection<int, PersonnelAvailability>
     */
    public function handle(
        StaffProfile $staff,
        string $date,
        string $startTime,
        string $endTime,
        string $changeReason,
        User $actor,
    ): Collection {
        // Validate the interval (start < end, within a day) before touching rows.
        $interval = TimeInterval::fromStrings($startTime, $endTime);

        return DB::transaction(function () use ($staff, $date, $interval, $changeReason, $actor): Collection {
            StaffProfile::query()->whereKey($staff->id)->lockForUpdate()->first();

            // "Create or replace": remove any existing UNAVAILABLE exception on this
            // date that overlaps the new interval so a re-issue is deterministic and
            // the GiST exclusion constraint cannot reject the insert.
            PersonnelAvailability::query()
                ->where('staff_profile_id', $staff->id)
                ->where('type', AvailabilityType::Exception->value)
                ->where('date', $date)
                ->where('available', false)
                ->where('start_time', '<', $interval->endString())
                ->where('end_time', '>', $interval->startString())
                ->delete();

            PersonnelAvailability::query()->create([
                'merchant_id' => $staff->merchant_id,
                'branch_id' => $staff->primary_branch_id,
                'staff_profile_id' => $staff->id,
                'weekday' => null,
                'date' => $date,
                'start_time' => $interval->startString(),
                'end_time' => $interval->endString(),
                'type' => AvailabilityType::Exception->value,
                'available' => false,
            ]);

            $this->audit->record(
                AuditEvent::PersonnelAvailabilityEmergencyUnavailable,
                $actor,
                $staff->merchant_id,
                $staff->primary_branch_id,
                $staff,
                [
                    'staff_profile_id' => $staff->ulid,
                    'date' => $date,
                    'start_time' => $interval->startString(),
                    'end_time' => $interval->endString(),
                    'change_reason' => $this->sanitizeReason($changeReason),
                ],
            );

            return PersonnelAvailability::query()
                ->where('staff_profile_id', $staff->id)
                ->orderBy('type')
                ->orderBy('weekday')
                ->orderBy('date')
                ->orderBy('start_time')
                ->get();
        });
    }

    private function sanitizeReason(string $reason): string
    {
        return $this->redactor->redactString(mb_substr(trim($reason), 0, 500));
    }
}
