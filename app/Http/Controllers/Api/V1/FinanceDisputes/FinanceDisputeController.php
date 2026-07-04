<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FinanceDisputes;

use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\FinanceOps\Actions\CreateFinanceDispute;
use App\Domain\FinanceOps\Actions\RejectFinanceDispute;
use App\Domain\FinanceOps\Actions\ResolveFinanceDispute;
use App\Domain\FinanceOps\Actions\StartFinanceDisputeReview;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinanceDisputes\CreateFinanceDisputeRequest;
use App\Http\Requests\FinanceDisputes\ResolveFinanceDisputeRequest;
use App\Http\Resources\FinanceDisputeResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Finance disputes (Plan §44; Phase 18B). Finance-only investigation over an invoice
 * and/or payment record; the disputed source record is never mutated. Private evidence
 * uses the Phase 10F `dispute_evidence` file domain (path never exposed).
 */
final class FinanceDisputeController extends Controller
{
    private const RELATIONS = ['invoice', 'paymentRecord'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FinanceDispute::class);

        $query = FinanceDispute::query()->with(self::RELATIONS);
        if (is_string($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }
        ApiPagination::applySort($query, is_string($request->query('sort')) ? $request->query('sort') : null, 'created_at');

        return FinanceDisputeResource::collection($query->paginate(ApiPagination::perPage($request->all()))->withQueryString());
    }

    public function store(CreateFinanceDisputeRequest $request, CreateFinanceDispute $action): JsonResponse
    {
        $this->authorize('create', FinanceDispute::class);

        $invoice = $this->resolveInvoice($request->validated('invoice'));
        $component = $this->resolveComponent($request->validated('payment_record'));
        $evidenceFileId = $this->resolveEvidenceFileId($request->validated('evidence_file'));

        /** @var User $actor */
        $actor = $request->user();
        $dispute = $action->handle($actor, $invoice, $component, (string) $request->validated('reason'), $evidenceFileId);

        return FinanceDisputeResource::make($dispute->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(FinanceDispute $financeDispute): FinanceDisputeResource
    {
        $this->authorize('view', $financeDispute);

        return FinanceDisputeResource::make($financeDispute->load(self::RELATIONS));
    }

    public function startReview(FinanceDispute $financeDispute, StartFinanceDisputeReview $action): FinanceDisputeResource
    {
        $this->authorize('transition', $financeDispute);

        /** @var User $actor */
        $actor = request()->user();

        return FinanceDisputeResource::make($action->handle($financeDispute, $actor)->load(self::RELATIONS));
    }

    public function resolve(ResolveFinanceDisputeRequest $request, FinanceDispute $financeDispute, ResolveFinanceDispute $action): FinanceDisputeResource
    {
        $this->authorize('transition', $financeDispute);

        /** @var User $actor */
        $actor = $request->user();

        return FinanceDisputeResource::make($action->handle($financeDispute, $actor, (string) $request->validated('resolution_note'))->load(self::RELATIONS));
    }

    public function reject(ResolveFinanceDisputeRequest $request, FinanceDispute $financeDispute, RejectFinanceDispute $action): FinanceDisputeResource
    {
        $this->authorize('transition', $financeDispute);

        /** @var User $actor */
        $actor = $request->user();

        return FinanceDisputeResource::make($action->handle($financeDispute, $actor, (string) $request->validated('resolution_note'))->load(self::RELATIONS));
    }

    private function resolveInvoice(mixed $ulid): ?Invoice
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }
        $invoice = Invoice::query()->where('ulid', $ulid)->first();
        if ($invoice === null) {
            throw new NotFoundHttpException;
        }
        $this->assertBranch($invoice->merchant_id, $invoice->branch_id);

        return $invoice;
    }

    private function resolveComponent(mixed $ulid): ?PaymentRecord
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }
        $record = PaymentRecord::query()->where('ulid', $ulid)->first();
        if ($record === null) {
            throw new NotFoundHttpException;
        }
        $this->assertBranch($record->merchant_id, $record->branch_id);

        return $record;
    }

    private function resolveEvidenceFileId(mixed $ulid): ?int
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }
        $file = UploadedFile::query()->where('ulid', $ulid)->where('purpose', FilePurpose::DisputeEvidence->value)->first();
        if ($file === null || $file->merchant_id !== $this->context->merchantId()) {
            throw new NotFoundHttpException;
        }

        return $file->id;
    }

    private function assertBranch(int $merchantId, int $branchId): void
    {
        if ($merchantId !== $this->context->merchantId() || ! $this->context->canAccessBranch($branchId)) {
            throw TenantAccessException::noBranchScope();
        }
    }
}
