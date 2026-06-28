<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Catalogue\Exceptions\EligibilityConflictException;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Assign a personnel member as eligible for a service (Plan §39; Phase 15A).
 *
 * HR authority (`personnel.eligibility.manage`) is enforced upstream. The service
 * and staff MUST share the same merchant AND branch (no cross-branch eligibility,
 * Scope) — re-asserted here as defence-in-depth. One row per (service, staff)
 * pair: a revoked row is reactivated; an already-active pair is a 409 conflict.
 */
final class AssignEligibility
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Service $service, StaffProfile $staff, User $actor): ServicePersonnelEligibility
    {
        if ($service->merchant_id !== $staff->merchant_id || $service->branch_id !== $staff->primary_branch_id) {
            throw new InvalidArgumentException('Service and personnel must belong to the same branch.');
        }

        return DB::transaction(function () use ($service, $staff, $actor): ServicePersonnelEligibility {
            $existing = ServicePersonnelEligibility::query()
                ->where('service_id', $service->id)
                ->where('staff_profile_id', $staff->id)
                ->first();

            if ($existing !== null && $existing->active) {
                throw EligibilityConflictException::alreadyActive();
            }

            if ($existing !== null) {
                $existing->active = true;
                $existing->updated_by = $actor->id;
                $existing->save();
                $eligibility = $existing;
            } else {
                $eligibility = ServicePersonnelEligibility::query()->create([
                    'merchant_id' => $service->merchant_id,
                    'branch_id' => $service->branch_id,
                    'service_id' => $service->id,
                    'staff_profile_id' => $staff->id,
                    'active' => true,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $this->audit->record(
                AuditEvent::PersonnelEligibilityAssigned,
                $actor,
                $service->merchant_id,
                $service->branch_id,
                $eligibility,
                ['service_id' => $service->ulid, 'staff_profile_id' => $staff->ulid],
            );

            return $eligibility;
        });
    }
}
