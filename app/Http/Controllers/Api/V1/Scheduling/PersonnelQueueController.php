<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\PersonnelQueueIndexRequest;
use App\Http\Resources\PersonnelQueueResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Personnel own-scope queue (Plan §37, §19; Phase 16B). A Personnel user sees ONLY
 * queue entries assigned to their OWN staff profile — enforced server-side
 * (`staff_profile_id == own profile`), never by a client-supplied filter. No
 * mutation, no branch-wide queue, no other personnel's queue, no unmasked contact,
 * no export (`personnel.my_queue.view`).
 */
final class PersonnelQueueController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(PersonnelQueueIndexRequest $request): AnonymousResourceCollection
    {
        abort_unless($this->context->can('personnel.my_queue.view'), 403);

        $profile = $this->ownStaffProfile();

        $query = QueueEntry::query()
            ->with(['service', 'client'])
            ->where('staff_profile_id', $profile === null ? 0 : $profile->id);

        $filters = $request->validated();

        if (($filters['active'] ?? false)) {
            $query->whereIn('status', QueueEntry::statusValues(QueueEntryStatus::activeStatuses()));
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'position');

        return PersonnelQueueResource::collection(
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
}
