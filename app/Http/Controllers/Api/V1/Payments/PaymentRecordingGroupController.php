<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payments;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\RecordCustomerPaymentException;
use App\Domain\Payments\Actions\RecordCustomerPaymentGroup;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Exceptions\PaymentRecordingException;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\ValueObjects\PaymentComponentInput;
use App\Domain\Payments\ValueObjects\PaymentRecordingResult;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\PaymentGroupIndexRequest;
use App\Http\Requests\Payments\RecordPaymentGroupRequest;
use App\Http\Resources\PaymentRecordingGroupResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Merchant-client payment recording operations (Plan §41; Phase 18A). Front Office
 * records (maker); Finance reads the pending groups and may record as a distinct
 * maker exception. Every mutation delegates to a transactional action that locks the
 * invoice, validates state/currency/balance, runs durable duplicate detection, and
 * writes audit; merchant/branch/maker/totals/status/allocations are derived
 * server-side and never accepted from the body. Recording is `financial_mutation`
 * (route-level idempotency). A suspected duplicate returns `409` with a masked meta.
 */
final class PaymentRecordingGroupController extends Controller
{
    private const RELATIONS = ['maker', 'invoice', 'records', 'records.allocations', 'records.referenceChecks'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(PaymentGroupIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PaymentRecordingGroup::class);

        $filters = $request->validated();
        $query = PaymentRecordingGroup::query()->with(['maker', 'invoice']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return PaymentRecordingGroupResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(PaymentRecordingGroup $paymentRecordingGroup): PaymentRecordingGroupResource
    {
        $this->authorize('view', $paymentRecordingGroup);

        return PaymentRecordingGroupResource::make($paymentRecordingGroup->load(self::RELATIONS));
    }

    public function store(RecordPaymentGroupRequest $request, Invoice $invoice, RecordCustomerPaymentGroup $action): JsonResponse
    {
        $this->authorize('record', PaymentRecordingGroup::class);
        $this->assertInvoiceBranch($invoice);

        /** @var User $actor */
        $actor = $request->user();

        return $this->respond($action->handle($invoice, $actor, $this->components($request)), $request);
    }

    public function storeException(RecordPaymentGroupRequest $request, Invoice $invoice, RecordCustomerPaymentException $action): JsonResponse
    {
        $this->authorize('recordException', PaymentRecordingGroup::class);
        $this->assertInvoiceBranch($invoice);

        /** @var User $actor */
        $actor = $request->user();

        return $this->respond($action->handle($invoice, $actor, $this->components($request)), $request);
    }

    /** Success → 201 with the group; a durable duplicate hold → 409 (returned, never thrown, so idempotent replay caches it). */
    private function respond(PaymentRecordingResult $result, RecordPaymentGroupRequest $request): JsonResponse
    {
        if ($result->held) {
            return PaymentRecordingException::duplicateSuspected($result->duplicateMeta)->render($request);
        }

        return PaymentRecordingGroupResource::make($result->group->load(self::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    private function assertInvoiceBranch(Invoice $invoice): void
    {
        if ($invoice->merchant_id !== $this->context->merchantId()
            || ! $this->context->canAccessBranch($invoice->branch_id)) {
            throw TenantAccessException::noBranchScope();
        }
    }

    /**
     * @return list<PaymentComponentInput>
     */
    private function components(RecordPaymentGroupRequest $request): array
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = $request->validated('components');

        return array_map(static fn (array $component): PaymentComponentInput => new PaymentComponentInput(
            method: PaymentMethod::from((string) $component['method']),
            amountMinor: (int) $component['amount_minor'],
            rawReference: isset($component['reference']) ? (string) $component['reference'] : null,
            paidAt: isset($component['paid_at'])
                ? CarbonImmutable::parse((string) $component['paid_at'])
                : CarbonImmutable::now(),
            currency: isset($component['currency']) ? (string) $component['currency'] : null,
        ), $raw);
    }
}
