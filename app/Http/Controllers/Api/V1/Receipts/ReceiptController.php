<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Receipts;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Files\Services\FileAccessService;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Receipts\Actions\ReissueReceipt;
use App\Domain\Receipts\Models\Receipt;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receipts\ReceiptIndexRequest;
use App\Http\Requests\Receipts\ReissueReceiptRequest;
use App\Http\Resources\ReceiptResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Receipt read / reissue / authorized download (Plan §43; Gate J; Phase 18B). Receipts
 * are issued AUTOMATICALLY on group validation — there is NO manual issue route. The
 * ULID is the public identifier; a receipt never exposes an internal id, a full/
 * normalized reference, a storage path, or a signed URL/signature in its body. Downloads
 * go through the Phase 10F file boundary (authorization re-checked at link issuance AND
 * at the actual byte stream).
 */
final class ReceiptController extends Controller
{
    public function index(ReceiptIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Receipt::class);

        $filters = $request->validated();
        $query = Receipt::query()->with('invoice');

        if (isset($filters['invoice'])) {
            $invoiceId = Invoice::query()->where('ulid', $filters['invoice'])->value('id');
            $query->where('invoice_id', $invoiceId ?? 0);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return ReceiptResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(Receipt $receipt): ReceiptResource
    {
        $this->authorize('view', $receipt);

        return ReceiptResource::make($receipt->load(['invoice', 'reissueOf']));
    }

    /** Reissue a receipt → a new immutable row + new gap-free number referencing the original. */
    public function reissue(ReissueReceiptRequest $request, Receipt $receipt, ReissueReceipt $action): JsonResponse
    {
        $this->authorize('reissue', $receipt);

        /** @var User $actor */
        $actor = $request->user();
        $reissue = $action->handle($receipt, $actor, (string) $request->validated('reason'));

        return ReceiptResource::make($reissue->load('invoice'))->response()->setStatusCode(201);
    }

    /**
     * Issue a short-lived signed download link for the receipt PDF through the Phase 10F
     * file boundary. Authorization is re-checked here (link issuance) and again at the
     * actual byte stream (files.download). Audits receipt.downloaded with SAFE context
     * only — never the storage path or the signed URL.
     */
    public function downloadLink(Receipt $receipt, FileAccessService $access, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('download', $receipt);

        $file = $receipt->file;
        if ($receipt->file_generation_status !== 'ready' || $file === null) {
            abort(409, 'The receipt PDF is not ready for download yet.');
        }

        /** @var User $user */
        $user = request()->user();
        // Re-check the Phase 10F file authorization at link issuance.
        $access->authorizeDownload($file, $user);

        $audit->record(AuditEvent::ReceiptDownloaded, $user, $receipt->merchant_id, $receipt->branch_id, $receipt, [
            'receipt_id' => $receipt->ulid,
            'receipt_number' => $receipt->receipt_number,
        ]);

        return response()->json(['data' => $access->issueSignedUrl($file)]);
    }
}
