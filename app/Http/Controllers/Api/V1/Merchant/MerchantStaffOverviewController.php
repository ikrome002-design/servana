<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Hr\Enums\StaffHistoryField;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\MerchantStaffOverviewIndexRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Narrow Merchant Administrator lifecycle directory (UI/UX plan §6.4.7; Phase UI-09).
 *
 * This is intentionally not the HR `GET /staff` resource: that payload contains personnel profile
 * fields including phone and is protected by HR-only `staff.view`. This projection is authorized
 * by the already-active owner lifecycle capability, returns memberships/invitations context only,
 * omits phone and client data, and creates no contact-export surface.
 */
final class MerchantStaffOverviewController extends Controller
{
    public function index(MerchantStaffOverviewIndexRequest $request, TenantContext $context): JsonResponse
    {
        $filters = $request->validated();
        $merchantId = $context->merchantId();
        abort_if($merchantId === null, 403);

        $query = MerchantUser::query()
            ->where('merchant_id', $merchantId)
            ->addSelect([
                'active_session_count' => HostSession::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('host_sessions.merchant_user_id', 'merchant_users.id')
                    ->whereNull('host_sessions.revoked_at'),
            ])
            ->with([
                'user:id,ulid,name,email,status,last_login_at',
                'staffProfile:id,ulid,merchant_user_id,display_name',
                'staffProfile.history' => static fn ($history) => $history
                    ->whereIn('field', [
                        StaffHistoryField::Role->value,
                        StaffHistoryField::Branch->value,
                        StaffHistoryField::Status->value,
                    ])
                    ->latest('id')
                    ->limit(10),
                'branchAssignments' => static fn ($assignments) => $assignments
                    ->latest('id')
                    ->with('branch:id,ulid,name,code'),
            ]);

        if (isset($filters['search'])) {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(static function ($matching) use ($search): void {
                $matching->whereHas('user', static fn ($user) => $user
                    ->where('name', 'ilike', $search)
                    ->orWhere('email', 'ilike', $search))
                    ->orWhereHas('staffProfile', static fn ($profile) => $profile
                        ->where('display_name', 'ilike', $search));
            });
        }

        if (isset($filters['role'])) {
            $query->where('role', $filters['role']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['branch_ulid'])) {
            $branchUlid = (string) $filters['branch_ulid'];
            $query->whereHas('branchAssignments.branch', static fn ($branch) => $branch->where('ulid', $branchUlid));
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, '-created_at');
        $paginator = $query->paginate(ApiPagination::perPage($filters))->withQueryString();

        /** @var User $actor */
        $actor = $request->user();
        $rows = collect($paginator->items())->map(static function (MerchantUser $membership) use ($actor): ?array {
            $profile = $membership->staffProfile;
            $user = $membership->user;
            if ($user === null) {
                return null;
            }

            $branches = $membership->branchAssignments
                ->where('status', BranchUserAssignmentStatus::Active)
                ->map(static function ($assignment): ?array {
                    $branch = $assignment->branch;
                    if ($branch === null) {
                        return null;
                    }

                    return [
                        'id' => $branch->ulid,
                        'name' => $branch->name,
                        'code' => $branch->code,
                    ];
                })->filter(static fn (?array $row): bool => $row !== null)->values()->all();

            $assignmentHistory = $membership->branchAssignments->map(static function ($assignment): ?array {
                $branch = $assignment->branch;
                if ($branch === null) {
                    return null;
                }

                return [
                    'branch' => $branch->name,
                    'status' => $assignment->status->value,
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'revoked_at' => $assignment->revoked_at?->toIso8601String(),
                ];
            })->filter(static fn (?array $row): bool => $row !== null)->values()->all();

            return [
                'id' => $membership->ulid,
                'staff_profile_id' => $profile?->ulid,
                'display_name' => $profile === null ? $user->name : $profile->display_name,
                'email' => $user->email,
                'role' => $membership->role->value,
                'status' => $membership->status->value,
                'account_status' => $user->status,
                'activated_at' => $membership->activated_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'branches' => $branches,
                'active_session_count' => (int) $membership->getAttribute('active_session_count'),
                'assignment_history' => $assignmentHistory,
                'status_history' => $profile?->history->map(static fn ($history): array => [
                    'field' => $history->field->value,
                    'from' => $history->field === StaffHistoryField::Status ? $history->old_value : null,
                    'to' => $history->field === StaffHistoryField::Status ? $history->new_value : null,
                    'changed_at' => $history->created_at?->toIso8601String(),
                ])->values()->all() ?? [],
                'can' => [
                    'manage_lifecycle' => $profile !== null
                        && $membership->user_id !== $actor->id
                        && $actor->can('manage', $profile),
                ],
            ];
        })->filter(static fn (?array $row): bool => $row !== null)->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
