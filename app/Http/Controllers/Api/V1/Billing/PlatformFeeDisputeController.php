<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\Actions\CreatePlatformFeeDispute;
use App\Domain\Billing\Actions\RejectPlatformFeeDispute;
use App\Domain\Billing\Actions\ResolvePlatformFeeDispute;
use App\Domain\Billing\Actions\StartPlatformFeeDisputeReview;
use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\RejectPlatformFeeDisputeRequest;
use App\Http\Requests\Billing\ResolvePlatformFeeDisputeRequest;
use App\Http\Requests\Billing\StorePlatformFeeDisputeRequest;
use App\Http\Resources\PlatformFeeDisputeResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Percentage platform-fee dispute API (Plan §13.10 [Correction 3]; Phase 20E, Increment 6). Merchant
 * scope. Create (`platform_fee.dispute`), scoped reads (`platform_fee.view`), and review/resolve/reject
 * (`platform_fee.dispute.review`; fresh step-up + idempotency on resolve). Named actions per transition —
 * NO generic status route, NO DELETE. Thin: authorize → validate → action → masked Resource. Tenant
 * isolation via BelongsToMerchant scope + tenant-safe route binding (foreign-tenant ULID → 404).
 */
final class PlatformFeeDisputeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlatformFeeDispute::class);

        $query = PlatformFeeDispute::query()->with(['ledgerEntry', 'subscriptionInvoice', 'assignedReviewer', 'createdBy', 'resolvedBy'])
            ->orderByDesc('id');

        if ($this->context->isBranchScoped()) {
            $query->whereIn('branch_id', $this->context->branchIds());
        }
        if (in_array($request->string('status')->value(), PlatformFeeDisputeStatus::values(), true)) {
            $query->where('status', $request->string('status')->value());
        }

        return PlatformFeeDisputeResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function store(StorePlatformFeeDisputeRequest $request, CreatePlatformFeeDispute $action): JsonResponse
    {
        $this->authorize('create', PlatformFeeDispute::class);

        $ledgerEntry = $this->resolveLedgerEntry($request->input('platform_fee_ledger_entry'));
        $invoice = $this->resolveSubscriptionInvoice($request->input('subscription_invoice'));
        $evidenceFileId = $this->resolveEvidenceFileId($request->input('evidence_file'));

        $merchantId = (int) $this->context->merchantId();
        $branchId = $ledgerEntry?->branch_id;

        $dispute = $action->handle(
            $this->actor($request),
            $merchantId,
            $branchId,
            $ledgerEntry,
            $invoice,
            (string) $request->validated('reason'),
            $evidenceFileId,
        );

        return PlatformFeeDisputeResource::make($dispute->load(['ledgerEntry', 'subscriptionInvoice', 'createdBy']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PlatformFeeDispute $platformFeeDispute): PlatformFeeDisputeResource
    {
        $this->authorize('view', $platformFeeDispute);
        $this->assertBranchVisible($platformFeeDispute);

        return PlatformFeeDisputeResource::make(
            $platformFeeDispute->load(['ledgerEntry', 'subscriptionInvoice', 'assignedReviewer', 'createdBy', 'resolvedBy']),
        );
    }

    public function review(Request $request, PlatformFeeDispute $platformFeeDispute, StartPlatformFeeDisputeReview $action): PlatformFeeDisputeResource
    {
        $this->authorize('review', $platformFeeDispute);

        return PlatformFeeDisputeResource::make(
            $action->handle($platformFeeDispute, $this->actor($request))->load(['assignedReviewer', 'createdBy']),
        );
    }

    public function resolve(ResolvePlatformFeeDisputeRequest $request, PlatformFeeDispute $platformFeeDispute, ResolvePlatformFeeDispute $action): PlatformFeeDisputeResource
    {
        $this->authorize('review', $platformFeeDispute);

        $moneyChange = $request->input('money_change_amount_minor');

        return PlatformFeeDisputeResource::make(
            $action->handle(
                $platformFeeDispute,
                $this->actor($request),
                (string) $request->validated('resolution_note'),
                $moneyChange === null ? null : (int) $moneyChange,
            )->load(['ledgerEntry', 'assignedReviewer', 'createdBy', 'resolvedBy']),
        );
    }

    public function reject(RejectPlatformFeeDisputeRequest $request, PlatformFeeDispute $platformFeeDispute, RejectPlatformFeeDispute $action): PlatformFeeDisputeResource
    {
        $this->authorize('review', $platformFeeDispute);

        return PlatformFeeDisputeResource::make(
            $action->handle($platformFeeDispute, $this->actor($request), (string) $request->validated('resolution_note'))
                ->load(['createdBy', 'resolvedBy']),
        );
    }

    private function resolveLedgerEntry(?string $ulid): ?PlatformFeeLedgerEntry
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        // Tenant-scoped: a foreign-tenant / unknown ULID that was explicitly provided is 404 (no leak).
        $entry = PlatformFeeLedgerEntry::query()->where('ulid', $ulid)->first();
        abort_if($entry === null, Response::HTTP_NOT_FOUND);

        return $entry;
    }

    private function resolveSubscriptionInvoice(?string $ulid): ?SubscriptionInvoice
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $invoice = SubscriptionInvoice::query()->where('ulid', $ulid)->first();
        abort_if($invoice === null, Response::HTTP_NOT_FOUND);

        return $invoice;
    }

    private function resolveEvidenceFileId(?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $file = UploadedFile::query()->where('ulid', $ulid)->first();
        abort_if($file === null, Response::HTTP_NOT_FOUND);

        return $file->id;
    }

    private function assertBranchVisible(PlatformFeeDispute $dispute): void
    {
        if ($this->context->isBranchScoped()
            && ! in_array($dispute->branch_id, $this->context->branchIds(), true)) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
