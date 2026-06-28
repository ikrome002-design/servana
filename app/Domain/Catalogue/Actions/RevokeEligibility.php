<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke a personnel-service eligibility (Plan §39; Phase 15A). HR authority is
 * enforced upstream. Sets `active = false` (the row is retained for history); a
 * later assign reactivates the same row. Revoke + audit in one transaction.
 */
final class RevokeEligibility
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ServicePersonnelEligibility $eligibility, User $actor): ServicePersonnelEligibility
    {
        return DB::transaction(function () use ($eligibility, $actor): ServicePersonnelEligibility {
            $eligibility->active = false;
            $eligibility->updated_by = $actor->id;
            $eligibility->save();

            $this->audit->record(
                AuditEvent::PersonnelEligibilityRevoked,
                $actor,
                $eligibility->merchant_id,
                $eligibility->branch_id,
                $eligibility,
                ['service_id' => $eligibility->service?->ulid, 'staff_profile_id' => $eligibility->staffProfile?->ulid],
            );

            return $eligibility;
        });
    }
}
