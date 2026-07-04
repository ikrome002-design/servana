<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PeriodLocks;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\FinanceOps\Actions\ApprovePeriodReopenException;
use App\Domain\FinanceOps\Actions\CreateFinancialPeriodLock;
use App\Domain\FinanceOps\Actions\ExecutePeriodReopen;
use App\Domain\FinanceOps\Actions\RequestPeriodReopen;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\PeriodLocks\CreateFinancialPeriodLockRequest;
use App\Http\Requests\PeriodLocks\PeriodLockIndexRequest;
use App\Http\Requests\PeriodLocks\PeriodReopenRequest;
use App\Http\Resources\FinancialPeriodLockResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Financial period locks + controlled reopen (Plan §46; ADR-0007; Phase 18B). Finance
 * owns lock creation + reopen execution (fresh MFA); a Merchant Administrator approves
 * an exceptional reopen only. Merchant-wide (branch null) or branch-specific scope.
 * Every mutation is `financial_mutation` (idempotency-keyed); the masked resource never
 * exposes an internal id. Reads are never period-locked.
 */
final class FinancialPeriodLockController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(PeriodLockIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FinancialPeriodLock::class);

        $filters = $request->validated();
        $query = FinancialPeriodLock::query()->with('branch');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'period_start');

        return FinancialPeriodLockResource::collection($query->paginate(ApiPagination::perPage($filters))->withQueryString());
    }

    public function store(CreateFinancialPeriodLockRequest $request, CreateFinancialPeriodLock $action): JsonResponse
    {
        $this->authorize('create', FinancialPeriodLock::class);

        $branchId = $this->resolveBranchId($request->validated('branch'));

        /** @var User $actor */
        $actor = $request->user();
        $lock = $action->handle(
            (int) $this->context->merchantId(),
            $branchId,
            (string) $request->validated('period_start'),
            (string) $request->validated('period_end'),
            $actor,
            (bool) $request->validated('exception_required', false),
        );

        return FinancialPeriodLockResource::make($lock->load('branch'))->response()->setStatusCode(201);
    }

    public function show(FinancialPeriodLock $periodLock): FinancialPeriodLockResource
    {
        $this->authorize('view', $periodLock);

        return FinancialPeriodLockResource::make($periodLock->load('branch'));
    }

    public function requestReopen(PeriodReopenRequest $request, FinancialPeriodLock $periodLock, RequestPeriodReopen $action): FinancialPeriodLockResource
    {
        $this->authorize('requestReopen', $periodLock);

        return FinancialPeriodLockResource::make(
            $action->handle($periodLock, $this->actor(), (string) $request->validated('reason'))->load('branch'),
        );
    }

    public function approveException(FinancialPeriodLock $periodLock, ApprovePeriodReopenException $action): FinancialPeriodLockResource
    {
        $this->authorize('approveException', $periodLock);

        return FinancialPeriodLockResource::make($action->handle($periodLock, $this->actor())->load('branch'));
    }

    public function execute(FinancialPeriodLock $periodLock, ExecutePeriodReopen $action): FinancialPeriodLockResource
    {
        $this->authorize('execute', $periodLock);

        return FinancialPeriodLockResource::make($action->handle($periodLock, $this->actor())->load('branch'));
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = request()->user();

        return $actor;
    }

    /** Resolve an optional branch ULID to its id within tenant scope (foreign → 404). */
    private function resolveBranchId(?string $branchUlid): ?int
    {
        if ($branchUlid === null || $branchUlid === '') {
            return null;
        }

        $branch = MerchantBranch::query()->where('ulid', $branchUlid)->first();
        if ($branch === null) {
            throw new NotFoundHttpException;
        }
        if ($branch->merchant_id !== $this->context->merchantId()) {
            throw TenantAccessException::noBranchScope();
        }

        return $branch->id;
    }
}
