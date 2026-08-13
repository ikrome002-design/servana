<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\BranchInvoiceVisibilityIndexRequest;
use App\Http\Requests\Branches\BranchPaymentVisibilityIndexRequest;
use App\Http\Resources\BranchInvoiceVisibilityResource;
use App\Http\Resources\BranchPaymentVisibilityResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Narrow read projections; no Finance or Front-Office policy is bypassed. */
final class BranchFinancialVisibilityController extends Controller
{
    public function invoices(
        BranchInvoiceVisibilityIndexRequest $request,
        MerchantBranch $branch,
    ): AnonymousResourceCollection {
        $filters = $request->validated();
        $query = Invoice::query()->where('branch_id', $branch->id);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return BranchInvoiceVisibilityResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function payments(
        BranchPaymentVisibilityIndexRequest $request,
        MerchantBranch $branch,
    ): AnonymousResourceCollection {
        $filters = $request->validated();
        $query = PaymentRecordingGroup::query()
            ->where('branch_id', $branch->id)
            ->with('invoice:id,ulid,invoice_number');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return BranchPaymentVisibilityResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }
}
