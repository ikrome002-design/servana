<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Domain\Billing\Actions\GenerateSubscriptionInvoicePdf;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Files\Services\FileAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\GenerateSubscriptionInvoicePdfRequest;
use App\Http\Resources\SubscriptionInvoiceResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant subscription-invoice self-service (Plan §49; Phase 20B). Merchant Administrator, merchant
 * scope. Reads the invoice list/detail (`merchant.subscription.invoice.view`), generates a NEW PDF
 * (`merchant.subscription.invoice.download`; blocked in billing read-only by the route gate + action
 * policy), and issues a signed download link for an EXISTING PDF (allowed even in billing read-only —
 * the download path never consults the billing gate). Reuses the Phase 10F file boundary for the
 * actual byte stream. NO issue / void / payment / Wallet endpoint lives here.
 */
final class SubscriptionInvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('view', SubscriptionInvoice::class);

        $query = SubscriptionInvoice::query()->orderByDesc('id');

        $status = $request->string('status')->value();
        if (in_array($status, SubscriptionInvoiceStatus::values(), true)) {
            $query->where('status', $status);
        }

        return SubscriptionInvoiceResource::collection(
            $query->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(SubscriptionInvoice $subscriptionInvoice): SubscriptionInvoiceResource
    {
        $this->authorize('view', $subscriptionInvoice);

        return SubscriptionInvoiceResource::make($subscriptionInvoice);
    }

    public function generatePdf(
        GenerateSubscriptionInvoicePdfRequest $request,
        SubscriptionInvoice $subscriptionInvoice,
        GenerateSubscriptionInvoicePdf $action,
    ): SubscriptionInvoiceResource {
        $this->authorize('download', SubscriptionInvoice::class);

        /** @var User $actor */
        $actor = $request->user();

        return SubscriptionInvoiceResource::make($action->handle($subscriptionInvoice, $actor));
    }

    public function downloadLink(SubscriptionInvoice $subscriptionInvoice, Request $request, FileAccessService $files): JsonResponse
    {
        $this->authorize('download', SubscriptionInvoice::class);

        $file = $subscriptionInvoice->file()->first();
        abort_if($file === null, Response::HTTP_NOT_FOUND);

        /** @var User $user */
        $user = $request->user();

        // Re-checks tenant ownership + file lifecycle (throws 404 on cross-tenant / revoked). Billing
        // read-only is intentionally NOT a download gate — an existing PDF stays downloadable.
        $files->authorizeDownload($file, $user);

        return response()->json(['data' => $files->issueSignedUrl($file)]);
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }
}
