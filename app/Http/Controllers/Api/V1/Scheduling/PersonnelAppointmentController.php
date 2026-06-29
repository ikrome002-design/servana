<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\PersonnelAppointmentIndexRequest;
use App\Http\Resources\PersonnelAppointmentResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Personnel own-scope appointments (Plan §36, §19.3; Phase 16A). A Personnel user
 * sees ONLY appointments assigned to their OWN staff profile — enforced
 * server-side (`assigned_personnel_staff_profile_id == own profile`), never by a
 * client-supplied filter. No mutation, no branch-wide search, no other personnel's
 * schedule, no unmasked contact, no export (`personnel.my_appointments.view`).
 */
final class PersonnelAppointmentController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(PersonnelAppointmentIndexRequest $request): AnonymousResourceCollection
    {
        abort_unless($this->context->can('personnel.my_appointments.view'), 403);

        $profile = $this->ownStaffProfile();

        // No staff profile → no own appointments (never an error, never a leak).
        $query = Appointment::query()
            ->with(['service', 'client'])
            ->where('assigned_personnel_staff_profile_id', $profile === null ? 0 : $profile->id);

        $filters = $request->validated();
        $this->applyDateFilters($query, $filters);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'starts_at');

        return PersonnelAppointmentResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    private function ownStaffProfile(): ?StaffProfile
    {
        $merchantUser = $this->context->merchantUser();

        if ($merchantUser === null) {
            return null;
        }

        return StaffProfile::query()
            ->where('merchant_user_id', $merchantUser->id)
            ->first();
    }

    /**
     * @param  Builder<Appointment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        $tz = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');

        if (isset($filters['date'])) {
            $start = CarbonImmutable::parse((string) $filters['date'], $tz)->startOfDay();
            $query->where('starts_at', '>=', $start)->where('starts_at', '<', $start->addDay());

            return;
        }

        if (isset($filters['date_from'])) {
            $query->where('starts_at', '>=', CarbonImmutable::parse((string) $filters['date_from'], $tz)->startOfDay());
        }
        if (isset($filters['date_to'])) {
            $query->where('starts_at', '<', CarbonImmutable::parse((string) $filters['date_to'], $tz)->startOfDay()->addDay());
        }
    }
}
