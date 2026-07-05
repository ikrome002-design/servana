<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Domain\Audit\Actions\RecordAuditExportDownload;
use App\Domain\Audit\Actions\RequestAuditExport;
use App\Domain\Audit\Actions\RevokeAuditExport;
use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Exceptions\AuditExportException;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Services\FileAccessService;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditExportIndexRequest;
use App\Http\Requests\Audit\RequestAuditExportRequest;
use App\Http\Resources\AuditExportResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Audit exports (Plan §13.5, §19.2/§19.3, §80; Phase 19; ADR-010). The Audit role
 * requests a reason-gated, branch-scoped, masked export (fresh step-up) generated async
 * on `reports-exports`, then downloads it via an authorized signed Phase 10F link.
 * Download accounting happens on the STREAM (not link issuance). The masked resource
 * never exposes the storage path, signed URL/signature, file bytes, or an internal id.
 * Branch scope + foreign-tenant 404 come from the model's tenant/branch global scopes.
 */
final class AuditExportController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(AuditExportIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditExport::class);

        $filters = $request->validated();
        $query = AuditExport::query()->with('branch');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return AuditExportResource::collection($query->paginate(ApiPagination::perPage($filters))->withQueryString());
    }

    public function store(RequestAuditExportRequest $request, RequestAuditExport $action): JsonResponse
    {
        $this->authorize('create', AuditExport::class);

        $branchId = $this->resolveAssignedBranchId((string) $request->validated('branch'));

        /** @var User $actor */
        $actor = $request->user();
        $export = $action->handle(
            (int) $this->context->merchantId(),
            $branchId,
            $request->scopeSnapshot(),
            (string) $request->validated('reason'),
            $actor,
        );

        return AuditExportResource::make($export->load('branch'))->response()->setStatusCode(201);
    }

    public function show(AuditExport $auditExport): AuditExportResource
    {
        $this->authorize('view', $auditExport);

        return AuditExportResource::make($auditExport->load('branch'));
    }

    /** Issue an authorized short-lived signed link to the download STREAM (no accounting here). */
    public function downloadLink(AuditExport $auditExport, FileAccessService $access): JsonResponse
    {
        $this->authorize('download', $auditExport);

        $file = $auditExport->file;
        if ($auditExport->status !== AuditExportStatus::Ready || $file === null) {
            throw AuditExportException::notDownloadable();
        }

        /** @var User $user */
        $user = request()->user();
        // Re-check the Phase 10F file authorization at link issuance.
        $access->authorizeDownload($file, $user);

        // Route signing is confined to the file domain (Plan §65 storage boundary); the
        // Audit export uses its OWN stream route so download accounting happens on the
        // authorized stream, not at issuance (ADR-010).
        $signed = $access->signDownloadRoute('audit-exports.download', ['auditExport' => $auditExport->ulid]);

        return response()->json(['data' => $signed]);
    }

    /**
     * Authorized signed STREAM (the accounting point). Re-checks authorization, records
     * the download atomically (count + first/last timestamps + audit_export.downloaded),
     * then streams the private file. A signature alone is transport, never authorization.
     */
    public function download(AuditExport $auditExport, FileAccessService $access, RecordAuditExportDownload $record): StreamedResponse
    {
        $this->authorize('download', $auditExport);

        $file = $auditExport->file;
        if ($auditExport->status !== AuditExportStatus::Ready || $file === null) {
            throw AuditExportException::notDownloadable();
        }

        /** @var User $user */
        $user = request()->user();
        $access->authorizeDownload($file, $user);

        $record->handle($auditExport, $user);

        return Storage::disk($file->storage_disk)->download(
            (string) $file->final_path,
            $file->safe_download_filename,
        );
    }

    public function revoke(AuditExport $auditExport, RevokeAuditExport $action): AuditExportResource
    {
        $this->authorize('revoke', $auditExport);

        /** @var User $actor */
        $actor = request()->user();

        return AuditExportResource::make($action->handle($auditExport, $actor)->load('branch'));
    }

    /** Resolve a branch ULID to an id the caller may access (foreign → 404, unassigned → denied). */
    private function resolveAssignedBranchId(string $branchUlid): int
    {
        $branch = MerchantBranch::query()->where('ulid', $branchUlid)->first();
        if ($branch === null || $branch->merchant_id !== $this->context->merchantId()) {
            throw new NotFoundHttpException;
        }
        if (! $this->context->canAccessBranch($branch->id)) {
            throw TenantAccessException::noBranchScope();
        }

        return $branch->id;
    }
}
