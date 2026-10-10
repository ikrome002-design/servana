<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Personnel;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Personnel\Services\PersonnelExperienceReadModel;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Personnel\PersonnelEarningsLedgerIndexRequest;
use App\Http\Requests\Personnel\PersonnelExperienceRequest;
use App\Http\Requests\Personnel\PersonnelPreferredRequestIndexRequest;
use App\Http\Requests\Personnel\PersonnelServedClientIndexRequest;
use App\Http\Requests\Personnel\PersonnelServiceHistoryIndexRequest;
use App\Http\Resources\PersonnelCommissionLedgerResource;
use App\Http\Resources\PersonnelSalaryLedgerResource;
use App\Http\Resources\PersonnelServedClientContextResource;
use App\Http\Resources\PersonnelServiceHistoryResource;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase UI-14 Personnel own-experience reads.
 *
 * The acting StaffProfile always comes from the authenticated membership. No route, query or body
 * accepts a staff identifier. Each endpoint also requires the existing capability for its owning
 * domain; this phase creates no permission key and no mutation.
 */
final class PersonnelExperienceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PersonnelExperienceReadModel $readModel,
        private readonly AuditRecorder $audit,
    ) {}

    public function dashboard(PersonnelExperienceRequest $request): JsonResponse
    {
        $this->requirePersonnelPermissions([
            'personnel.my_appointments.view',
            'personnel.my_queue.view',
            'personnel.my_sessions.view',
            'personnel.my_earnings.view',
        ]);

        $data = $this->readModel->dashboard($this->ownStaffProfileOrFail());
        $data['clients']['can_compose_sms'] = $this->context->can('personnel.my_sms.send');

        return response()->json(['data' => $data]);
    }

    public function history(PersonnelServiceHistoryIndexRequest $request): AnonymousResourceCollection
    {
        $this->requirePersonnelPermissions(['personnel.my_sessions.view']);
        $staff = $this->ownStaffProfileOrFail();
        $filters = $request->validated();

        return PersonnelServiceHistoryResource::collection($this->readModel->serviceHistory($staff, $filters))
            ->additional(['summary' => $this->readModel->serviceHistorySummary($staff, $filters)]);
    }

    public function preferredRequests(PersonnelPreferredRequestIndexRequest $request): JsonResponse
    {
        $this->requirePersonnelPermissions(['personnel.my_queue.view', 'personnel.my_appointments.view']);
        $paginator = $this->readModel->preferredRequests($this->ownStaffProfileOrFail(), $request->validated());

        return response()->json([
            'data' => $paginator->items(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function servedClients(PersonnelServedClientIndexRequest $request): AnonymousResourceCollection
    {
        $this->requirePersonnelPermissions(['personnel.my_served_clients.view']);
        $staff = $this->ownStaffProfileOrFail();

        /** @var User $actor */
        $actor = $request->user();
        $this->audit->record(
            AuditEvent::PersonnelServedClientsViewed,
            $actor,
            $staff->merchant_id,
            $staff->primary_branch_id,
            $staff,
            ['scope' => 'own', 'contact_shape' => 'masked', 'export' => false],
        );

        return PersonnelServedClientContextResource::collection(
            $this->readModel->servedClientContexts($staff, $request->validated()),
        );
    }

    public function commissions(PersonnelEarningsLedgerIndexRequest $request): AnonymousResourceCollection
    {
        $this->requirePersonnelPermissions(['personnel.my_earnings.view']);

        return PersonnelCommissionLedgerResource::collection(
            $this->readModel->commissions($this->ownStaffProfileOrFail(), $request->validated()),
        );
    }

    public function salary(PersonnelEarningsLedgerIndexRequest $request): AnonymousResourceCollection
    {
        $this->requirePersonnelPermissions(['personnel.my_earnings.view']);

        return PersonnelSalaryLedgerResource::collection(
            $this->readModel->salary($this->ownStaffProfileOrFail(), $request->validated()),
        );
    }

    public function availability(PersonnelExperienceRequest $request): JsonResponse
    {
        // The existing appointments-read capability is Personnel-only and anchors this schedule
        // read to the same own-work boundary. HR retains the sole availability mutation key.
        $this->requirePersonnelPermissions(['personnel.my_appointments.view']);

        return response()->json(['data' => $this->readModel->availability($this->ownStaffProfileOrFail())]);
    }

    /** @param list<string> $permissions */
    private function requirePersonnelPermissions(array $permissions): void
    {
        abort_unless($this->context->role() === MerchantUserRole::Personnel, Response::HTTP_FORBIDDEN);
        foreach ($permissions as $permission) {
            abort_unless($this->context->can($permission), Response::HTTP_FORBIDDEN);
        }
    }

    private function ownStaffProfileOrFail(): StaffProfile
    {
        $merchantUser = $this->context->merchantUser();
        $profile = $merchantUser === null
            ? null
            : StaffProfile::query()->where('merchant_user_id', $merchantUser->id)->first();

        abort_if($profile === null, Response::HTTP_NOT_FOUND);

        return $profile;
    }

    /** @param LengthAwarePaginator<int, mixed> $paginator
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
