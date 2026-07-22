<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Compensation\Actions\GenerateEarningsStatement;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Services\PersonnelEarningsReadModel;
use App\Domain\Files\Services\FileAccessService;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\PersonnelEarningsIndexRequest;
use App\Http\Requests\Compensation\PersonnelEarningsStatementRequest;
use App\Http\Resources\EarningsStatementResource;
use App\Http\Resources\PersonnelPayoutItemResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 20H personnel own-scope earnings API (Plan §63, §10.2, §19.3; §H10/§H11). The acting staff
 * profile is derived from the authenticated membership — a browser NEVER chooses the subject; arbitrary
 * ids are rejected. Reads are server-authoritative (the SPA computes no totals), per-currency, and never
 * combined across currencies. Statement generation is on-demand + idempotent for a PAID payout item;
 * the bytes are fetched through the authorized 10F download endpoints (own-scope by `owner_user_id`).
 * No other staff's data, no export, no mutation of any money fact, no Wallet/provider field.
 */
final class PersonnelEarningsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PersonnelEarningsReadModel $readModel,
        private readonly GenerateEarningsStatement $generateStatement,
        private readonly FileAccessService $files,
    ) {}

    public function overview(PersonnelEarningsIndexRequest $request): JsonResponse
    {
        abort_unless($this->context->can('personnel.my_earnings.view'), 403);
        $staff = $this->ownStaffProfileOrFail();

        return response()->json([
            'data' => [
                'tab_visibility' => $this->readModel->tabVisibility($staff),
                'currencies' => $this->readModel->overview($staff),
            ],
        ]);
    }

    public function compensation(PersonnelEarningsIndexRequest $request): JsonResponse
    {
        abort_unless($this->context->can('personnel.my_compensation.view'), 403);
        $staff = $this->ownStaffProfileOrFail();

        return response()->json(['data' => $this->readModel->compensationTerms($staff)]);
    }

    public function payouts(PersonnelEarningsIndexRequest $request): AnonymousResourceCollection
    {
        abort_unless($this->context->can('personnel.my_payouts.view'), 403);
        $staff = $this->ownStaffProfileOrFail();

        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return PersonnelPayoutItemResource::collection($this->readModel->payoutHistory($staff, $perPage));
    }

    public function generateStatement(PersonnelEarningsStatementRequest $request, PersonnelPayoutItem $personnelPayoutItem): JsonResponse
    {
        abort_unless($this->context->can('personnel.my_statements.download'), 403);
        $staff = $this->ownStaffProfileOrFail();

        // Own-scope: the item must belong to the acting personnel (no existence leak on a foreign item).
        if ($personnelPayoutItem->staff_profile_id !== $staff->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $file = $this->generateStatement->handle($personnelPayoutItem);

        /** @var User $user */
        $user = $request->user();
        // Defence-in-depth: re-check download authority (own-scope by owner_user_id) before signing.
        $this->files->authorizeView($file, $user);

        return response()->json([
            'data' => [
                'statement' => EarningsStatementResource::make($file),
                'download' => $this->files->issueSignedUrl($file),
            ],
        ]);
    }

    /** Resolve the acting personnel's own staff profile (own-scope is never client-selectable). */
    private function ownStaffProfileOrFail(): StaffProfile
    {
        $merchantUser = $this->context->merchantUser();

        $profile = $merchantUser === null
            ? null
            : StaffProfile::query()->where('merchant_user_id', $merchantUser->id)->first();

        if ($profile === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $profile;
    }
}
