<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\FinanceOps\Services\FinanceWorkspaceReadModel;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceDuplicateReviewIndexRequest;
use App\Http\Requests\Finance\FinancePartialSplitIndexRequest;
use App\Http\Requests\Finance\FinanceWorkspaceOverviewRequest;
use App\Http\Resources\FinanceDuplicateReviewResource;
use App\Http\Resources\FinancePartialSplitInvoiceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** UI-12 read-only Finance presentation endpoints over existing domain authority. */
final class FinanceWorkspaceController extends Controller
{
    public function show(
        FinanceWorkspaceOverviewRequest $request,
        FinanceWorkspaceReadModel $readModel,
    ): JsonResponse {
        return response()->json(['data' => ['overview' => $readModel->read()]]);
    }

    public function duplicates(FinanceDuplicateReviewIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = PaymentReferenceCheck::query()
            ->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)
            ->whereNull('override_by')
            ->with([
                'record.group.invoice',
                'record.group.maker',
                'matchedRecord.group.invoice',
            ]);

        if (isset($filters['method'])) {
            $query->where('method', $filters['method']);
        }
        ApiPagination::applySort($query, $filters['sort'] ?? null, 'checked_at');

        return FinanceDuplicateReviewResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function partialSplit(FinancePartialSplitIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Invoice::query()
            ->where(static function (Builder $invoices): void {
                $invoices
                    ->where('status', InvoiceStatus::PartiallyPaid->value)
                    ->orHas('paymentGroups', '>', 1)
                    ->orWhereHas(
                        'paymentGroups',
                        static fn (Builder $groups): Builder => $groups->has('records', '>', 1),
                    );
            })
            ->with([
                'paymentGroups',
                'paymentGroups.maker',
                'paymentGroups.records.referenceChecks',
                'paymentGroups.validatedEvent.receipt',
            ]);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return FinancePartialSplitInvoiceResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }
}
