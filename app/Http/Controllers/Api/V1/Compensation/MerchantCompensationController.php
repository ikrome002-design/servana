<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Compensation\Actions\ApprovePayoutRunHighValue;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Services\MerchantCompensationSummaryReadModel;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\ApproveHighValuePayoutRunRequest;
use App\Http\Requests\Compensation\MerchantCompensationSummaryRequest;
use App\Http\Requests\Compensation\PayoutRunIndexRequest;
use App\Http\Resources\PersonnelPayoutRunResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 20H Merchant-Administrator compensation API (Plan §62/§63, §10.2, §19.3). The Merchant
 * Administrator holds ONLY the compensation-summary READ + high-value payout approval — never
 * create/verify/standard-approve/mark-paid (Plan §10.2). The summary is merchant-wide, masked, and
 * currency-grouped (never combined). High-value approval moves a run awaiting Merchant-Admin approval
 * (routed there by Finance verification) to `approved`; it carries MFA (group) + fresh step-up +
 * Idempotency-Key at the route and runs under a row-locked domain action. Tenant isolation is enforced
 * by the model global scopes. **Servana moves no money.**
 */
final class MerchantCompensationController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MerchantCompensationSummaryReadModel $summaryReadModel,
        private readonly ApprovePayoutRunHighValue $approveHighValueAction,
    ) {}

    public function summary(MerchantCompensationSummaryRequest $request): JsonResponse
    {
        abort_unless($this->context->can('merchant.compensation_summary.view'), 403);

        return response()->json([
            'data' => $this->summaryReadModel->summary((int) $this->context->merchantId()),
        ]);
    }

    public function index(PayoutRunIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAsMerchantAdmin', PersonnelPayoutRun::class);

        // The Merchant-Admin queue is the high-value runs awaiting approval; a status filter may
        // narrow further within the merchant.
        $query = PersonnelPayoutRun::query()
            ->withCount('items')
            ->with('branch:id,ulid')
            ->where('status', PayoutRunStatus::PendingMerchantAdminApproval->value);

        if ($request->filled('currency')) {
            $query->where('currency', (string) $request->string('currency'));
        }

        return PersonnelPayoutRunResource::collection(
            $query->orderByDesc('id')->paginate(min(max((int) $request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function show(PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('viewAsMerchantAdmin', PersonnelPayoutRun::class);

        return PersonnelPayoutRunResource::make($this->loadDetail($personnelPayoutRun));
    }

    public function approveHighValue(ApproveHighValuePayoutRunRequest $request, PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('approveHighValue', $personnelPayoutRun);

        /** @var User $actor */
        $actor = $request->user();

        return PersonnelPayoutRunResource::make($this->loadDetail($this->approveHighValueAction->handle($personnelPayoutRun, $actor)));
    }

    private function loadDetail(PersonnelPayoutRun $run): PersonnelPayoutRun
    {
        return $run->load([
            'branch:id,ulid',
            'items.staffProfile:id,ulid,display_name',
            'items.payoutRun:id,ulid',
            'items.earningsStatementFile:id,ulid',
        ])->loadCount('items');
    }
}
