<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FinanceExports;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Services\FileAccessService;
use App\Domain\FinanceOps\Actions\RequestFinanceExport;
use App\Domain\FinanceOps\Actions\RevokeFinanceExport;
use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use App\Domain\FinanceOps\Enums\FinanceExportType;
use App\Domain\FinanceOps\Exceptions\FinanceExportException;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinanceExports\FinanceExportIndexRequest;
use App\Http\Requests\FinanceExports\RequestFinanceExportRequest;
use App\Http\Resources\FinanceExportResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Finance exports (Plan §65, §67; Gate I; Phase 18B). Finance requests a scoped, masked
 * export (fresh step-up) generated async on `reports-exports`, then downloads it via an
 * authorized signed Phase 10F link. `finance_export.*` is `PL n/a`. The masked resource
 * never exposes the storage path, signed URL/signature, file bytes, or an internal id.
 */
final class FinanceExportController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(FinanceExportIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FinanceExport::class);

        $filters = $request->validated();
        $query = FinanceExport::query()->with('branch');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return FinanceExportResource::collection($query->paginate(ApiPagination::perPage($filters))->withQueryString());
    }

    public function store(RequestFinanceExportRequest $request, RequestFinanceExport $action): JsonResponse
    {
        $this->authorize('create', FinanceExport::class);

        $branchId = $this->resolveBranchId($request->validated('branch'));

        /** @var User $actor */
        $actor = $request->user();
        $export = $action->handle(
            FinanceExportType::from((string) $request->validated('export_type')),
            $branchId,
            (array) ($request->validated('filters') ?? []),
            (string) $request->validated('reason'),
            $actor,
        );

        return FinanceExportResource::make($export->load('branch'))->response()->setStatusCode(201);
    }

    public function show(FinanceExport $financeExport): FinanceExportResource
    {
        $this->authorize('view', $financeExport);

        return FinanceExportResource::make($financeExport->load('branch'));
    }

    /** Issue an authorized signed download link + record the download (atomic accounting). */
    public function downloadLink(FinanceExport $financeExport, FileAccessService $access, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('download', $financeExport);

        $file = $financeExport->file;
        if ($financeExport->status !== FinanceExportStatus::Ready || $file === null) {
            throw FinanceExportException::notDownloadable();
        }

        /** @var User $user */
        $user = request()->user();
        // Re-check the Phase 10F file authorization at link issuance.
        $access->authorizeDownload($file, $user);

        // Atomic download accounting: increment count, set first-once, update last.
        DB::transaction(function () use ($financeExport): void {
            /** @var FinanceExport $locked */
            $locked = FinanceExport::query()->whereKey($financeExport->id)->lockForUpdate()->firstOrFail();
            $locked->download_count = $locked->download_count + 1;
            if ($locked->first_downloaded_at === null) {
                $locked->first_downloaded_at = now();
            }
            $locked->last_downloaded_at = now();
            $locked->save();
        });

        $audit->record(AuditEvent::FinanceExportDownloaded, $user, $financeExport->merchant_id, $financeExport->branch_id, $financeExport, [
            'export_id' => $financeExport->ulid,
            'export_type' => $financeExport->export_type->value,
        ]);

        return response()->json(['data' => $access->issueSignedUrl($file)]);
    }

    public function revoke(FinanceExport $financeExport, RevokeFinanceExport $action): FinanceExportResource
    {
        $this->authorize('revoke', $financeExport);

        /** @var User $actor */
        $actor = request()->user();

        return FinanceExportResource::make($action->handle($financeExport, $actor)->load('branch'));
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
