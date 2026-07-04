<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Refunds;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Refunds\Actions\ApproveRefund;
use App\Domain\Refunds\Actions\FinalizeRefund;
use App\Domain\Refunds\Actions\RejectRefund;
use App\Domain\Refunds\Actions\RequestRefund;
use App\Domain\Refunds\Models\Refund;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Refunds\RefundIndexRequest;
use App\Http\Requests\Refunds\RequestRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * External refunds (Plan §44; Gate D/E; Phase 18B). Servana records an EXTERNAL refund;
 * it never moves funds. Maker (refund.create) requests against a validated component;
 * a distinct Finance membership approves (refund.approve, fresh step-up) and finalizes
 * (refund.finalize, fresh step-up). Every mutation is `financial_mutation` (route-level
 * idempotency) and period-lock-gated in the action. The masked resource never exposes
 * the plaintext external reference or an internal id.
 */
final class RefundController extends Controller
{
    private const RELATIONS = ['invoice', 'paymentRecord'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(RefundIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Refund::class);

        $filters = $request->validated();
        $query = Refund::query()->with(self::RELATIONS);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return RefundResource::collection($query->paginate(ApiPagination::perPage($filters))->withQueryString());
    }

    public function store(RequestRefundRequest $request, RequestRefund $action): JsonResponse
    {
        $this->authorize('create', Refund::class);

        $component = $this->resolveComponent((string) $request->validated('payment_record'));

        /** @var User $actor */
        $actor = $request->user();
        $refund = $action->handle(
            $component,
            $actor,
            (int) $request->validated('amount_minor'),
            PaymentMethod::from((string) $request->validated('method')),
            (string) $request->validated('reason'),
            $request->validated('reference') !== null ? (string) $request->validated('reference') : null,
        );

        return RefundResource::make($refund->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(Refund $refund): RefundResource
    {
        $this->authorize('view', $refund);

        return RefundResource::make($refund->load(self::RELATIONS));
    }

    public function approve(Refund $refund, ApproveRefund $action): RefundResource
    {
        $this->authorize('approve', $refund);

        /** @var User $actor */
        $actor = request()->user();

        return RefundResource::make($action->handle($refund, $actor)->load(self::RELATIONS));
    }

    public function reject(Refund $refund, RejectRefund $action): RefundResource
    {
        $this->authorize('reject', $refund);

        /** @var User $actor */
        $actor = request()->user();

        return RefundResource::make($action->handle($refund, $actor)->load(self::RELATIONS));
    }

    public function finalize(Refund $refund, FinalizeRefund $action): RefundResource
    {
        $this->authorize('finalize', $refund);

        /** @var User $actor */
        $actor = request()->user();

        return RefundResource::make($action->handle($refund, $actor)->load(self::RELATIONS));
    }

    /** Resolve a validated component by ULID within tenant + branch scope. */
    private function resolveComponent(string $ulid): PaymentRecord
    {
        $component = PaymentRecord::query()->where('ulid', $ulid)->first();

        if ($component === null) {
            throw new NotFoundHttpException;
        }
        if ($component->merchant_id !== $this->context->merchantId() || ! $this->context->canAccessBranch($component->branch_id)) {
            throw TenantAccessException::noBranchScope();
        }

        return $component;
    }
}
