<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\CashUps;

use App\Domain\Branches\Actions\ApproveCashUp;
use App\Domain\Branches\Actions\CreateOrUpdateCashUpDraft;
use App\Domain\Branches\Actions\LockApprovedCashUp;
use App\Domain\Branches\Actions\RejectCashUp;
use App\Domain\Branches\Actions\RequestCashUpCorrection;
use App\Domain\Branches\Actions\ResubmitCashUp;
use App\Domain\Branches\Actions\SubmitCashUp;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Branches\Services\CashUpExpectedTotalCalculator;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Currency;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\CashUps\CashUpDecisionRequest;
use App\Http\Requests\CashUps\CashUpIndexRequest;
use App\Http\Requests\CashUps\UpdateCashUpDraftRequest;
use App\Http\Resources\CashUpResource;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Branch cash-up + day-close reconciliation (Plan §45; ADR-0007; Phase 18B). Maker =
 * Branch Manager (draft/update/submit/resubmit, `branch.cash_up.submit`); checker =
 * Finance (review/approve/reject/request-correction/lock). Expected totals are
 * server-derived (Gate H) — never client input. The submitted/approved snapshot is
 * never destructively overwritten. Every mutation is period-lock-gated in the action
 * (→ 423) and classified `financial_mutation` (idempotency-keyed) on the route.
 */
final class CashUpController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CashUpExpectedTotalCalculator $calculator,
    ) {}

    /** Finance review inbox: cash-ups across accessible branches, paginated. */
    public function index(CashUpIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BranchCashUp::class);

        $filters = $request->validated();
        $query = BranchCashUp::query()->with('lines')->whereNotNull('business_date');

        if (! $this->context->isBranchScoped()) {
            // Merchant-wide readers still only see their own merchant (global scope);
            // branch-scoped readers are constrained to their assigned branches.
        } else {
            $query->whereIn('branch_id', $this->context->branchIds());
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['business_date'])) {
            $query->where('business_date', $filters['business_date']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'business_date');

        return CashUpResource::collection($query->paginate(ApiPagination::perPage($filters))->withQueryString());
    }

    /** Branch-day view: the persisted cash-up, or a server-computed expected preview. */
    public function branchDay(MerchantBranch $branch, string $date): JsonResponse
    {
        $this->authorizeBranch($branch);

        $cashUp = BranchCashUp::query()
            ->where('branch_id', $branch->id)
            ->where('business_date', $date)
            ->with('lines')
            ->first();

        if ($cashUp !== null) {
            return CashUpResource::make($cashUp)->response();
        }

        return response()->json(['data' => $this->preview($branch, $date)]);
    }

    /** Create/update the branch-day draft counts (Branch Manager). Idempotent PUT → 200. */
    public function upsertDraft(UpdateCashUpDraftRequest $request, MerchantBranch $branch, string $date, CreateOrUpdateCashUpDraft $action): JsonResponse
    {
        $this->authorizeBranch($branch);

        /** @var User $actor */
        $actor = $request->user();

        return CashUpResource::make($action->handle($branch, $date, $request->counts(), $actor))
            ->response()
            ->setStatusCode(200);
    }

    public function show(BranchCashUp $cashUp): CashUpResource
    {
        $this->authorize('view', $cashUp);

        return CashUpResource::make($cashUp->load('lines'));
    }

    public function submit(BranchCashUp $cashUp, SubmitCashUp $action): CashUpResource
    {
        $this->authorize('submit', $cashUp);

        return CashUpResource::make($action->handle($cashUp, $this->actor()));
    }

    public function resubmit(BranchCashUp $cashUp, ResubmitCashUp $action): CashUpResource
    {
        $this->authorize('resubmit', $cashUp);

        return CashUpResource::make($action->handle($cashUp, $this->actor()));
    }

    public function approve(BranchCashUp $cashUp, ApproveCashUp $action): CashUpResource
    {
        $this->authorize('approve', $cashUp);

        return CashUpResource::make($action->handle($cashUp, $this->actor()));
    }

    public function lock(BranchCashUp $cashUp, LockApprovedCashUp $action): CashUpResource
    {
        $this->authorize('lock', $cashUp);

        return CashUpResource::make($action->handle($cashUp, $this->actor()));
    }

    public function reject(CashUpDecisionRequest $request, BranchCashUp $cashUp, RejectCashUp $action): CashUpResource
    {
        $this->authorize('reject', $cashUp);

        return CashUpResource::make($action->handle($cashUp, $this->actor(), (string) $request->validated('reason')));
    }

    public function requestCorrection(CashUpDecisionRequest $request, BranchCashUp $cashUp, RequestCashUpCorrection $action): CashUpResource
    {
        $this->authorize('requestCorrection', $cashUp);

        return CashUpResource::make($action->handle($cashUp, $this->actor(), (string) $request->validated('reason')));
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = request()->user();

        return $actor;
    }

    /** Same-merchant + branch access, else foreign 404 / out-of-branch 403. */
    private function authorizeBranch(MerchantBranch $branch): void
    {
        $this->authorize('viewAny', BranchCashUp::class);

        if ($branch->merchant_id !== $this->context->merchantId() || ! $this->context->canAccessBranch($branch->id)) {
            throw TenantAccessException::noBranchScope();
        }
    }

    /**
     * Server-computed expected preview when no cash-up is yet persisted for the day.
     *
     * @return array<string, mixed>
     */
    private function preview(MerchantBranch $branch, string $date): array
    {
        $expected = $this->calculator->forBranchDay($branch->merchant_id, $branch->id, $date);
        $currency = Currency::KES;

        $lines = [];
        $total = 0;
        foreach ($expected as $method => $exp) {
            if ($exp === 0) {
                continue;
            }
            $lines[] = ['method' => $method, 'expected_minor' => $exp, 'counted_minor' => 0, 'variance_minor' => -$exp];
            $total += $exp;
        }

        return [
            'id' => null,
            'business_date' => $date,
            'status' => 'draft',
            'expected' => Money::ofMinor($total, $currency)->toArray(),
            'counted' => Money::ofMinor(0, $currency)->toArray(),
            'variance' => Money::ofMinor(-$total, $currency)->toArray(),
            'expected_minor' => $total,
            'counted_minor' => 0,
            'variance_minor' => -$total,
            'lines' => $lines,
        ];
    }
}
