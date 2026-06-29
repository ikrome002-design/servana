<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Support\ScheduleNormalizer;
use App\Models\User;
use App\Support\Redaction\Redactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Atomically REPLACE a staff member's canonical availability schedule
 * (Plan §13.7, §80 Phase 15B). HR authority (`personnel.availability.manage`) and
 * same-branch scope are enforced upstream (route + policy).
 *
 * The whole replacement is one transaction under a row lock on the staff anchor:
 * the payload is structurally validated (defence-in-depth over the DB CHECK +
 * exclusion constraints), the existing recurring + exception rows are deleted, and
 * the new set is inserted. Either the complete new schedule commits or the
 * complete old schedule is preserved — concurrent replacements cannot interleave.
 * Exactly one coherent audit event is written (not one per row), carrying only safe
 * counts + a SANITISED change reason.
 */
final class ReplaceAvailability
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ScheduleNormalizer $normalizer,
        private readonly Redactor $redactor,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $recurring
     * @param  array<int, array<string, mixed>>  $exceptions
     * @return Collection<int, PersonnelAvailability>
     */
    public function handle(
        StaffProfile $staff,
        array $recurring,
        array $exceptions,
        string $changeReason,
        User $actor,
    ): Collection {
        $normalized = $this->normalizer->normalize($recurring, $exceptions);

        return DB::transaction(function () use ($staff, $normalized, $changeReason, $actor): Collection {
            // Serialize concurrent replacements on the staff anchor so two writers
            // cannot interleave rows from different schedules.
            StaffProfile::query()->whereKey($staff->id)->lockForUpdate()->first();

            PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->delete();

            $rows = [...$normalized['recurring'], ...$normalized['exceptions']];
            foreach ($rows as $row) {
                PersonnelAvailability::query()->create([
                    'merchant_id' => $staff->merchant_id,
                    'branch_id' => $staff->primary_branch_id,
                    'staff_profile_id' => $staff->id,
                    'weekday' => $row['weekday'],
                    'date' => $row['date'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'type' => $row['type'],
                    'available' => $row['available'],
                ]);
            }

            $this->audit->record(
                AuditEvent::PersonnelAvailabilityUpdated,
                $actor,
                $staff->merchant_id,
                $staff->primary_branch_id,
                $staff,
                [
                    'staff_profile_id' => $staff->ulid,
                    'recurring_count' => count($normalized['recurring']),
                    'exception_count' => count($normalized['exceptions']),
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

    /** Cap length + mask any embedded email/phone before the reason reaches audit. */
    private function sanitizeReason(string $reason): string
    {
        return $this->redactor->redactString(mb_substr(trim($reason), 0, 500));
    }
}
