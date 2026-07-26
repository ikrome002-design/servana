<?php

declare(strict_types=1);

namespace App\Domain\Search\Definitions;

use App\Domain\Receipts\Models\Receipt;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Support\SearchLikeTerm;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `receipt` — branch-scoped receipt search by receipt number, its invoice number and reference
 * (Phase 22). Authority is `ReceiptPolicy::viewAny` (= `receipt.view`).
 *
 * NO CLIENT DATA AT ALL. `ReceiptResource` exposes no client — only the receipt number, amount,
 * components and the parent invoice's number — so under catalogue Rule 2 ("never more revealing than
 * the safest existing resource for the type") a receipt search document carries no client name and a
 * receipt result carries no client subtitle. This is the one type where the client name is withheld
 * even though the client is reachable through the relation, and it is withheld deliberately.
 *
 * `components` (the per-method payment breakdown) is excluded too: it is a payment-method
 * composition, not an identifier, and payment-provider material never enters a search index
 * (Plan §68 integration-exclusion rule).
 *
 * @extends AbstractSearchDocumentDefinition<Receipt>
 */
final class ReceiptSearchDefinition extends AbstractSearchDocumentDefinition
{
    public function type(): SearchDocumentType
    {
        return SearchDocumentType::Receipt;
    }

    public function indexName(): string
    {
        return 'receipts';
    }

    public function modelClass(): string
    {
        return Receipt::class;
    }

    public function canSearch(SearchContext $context): bool
    {
        return $context->can('receipt.view');
    }

    protected function table(): string
    {
        return 'receipts';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return Receipt::query()
            ->where('receipts.merchant_id', $context->merchantId)
            ->whereIn('receipts.branch_id', $context->branchIds);
    }

    protected function applyTextMatch(Builder $query, string $term): void
    {
        $pattern = SearchLikeTerm::contains($term);

        // `receipt_number` is an INTEGER column, so it is matched by equality on a numeric term
        // rather than by ILIKE — a text operator on an integer column is a PostgreSQL type error,
        // and casting it would mean raw SQL for no gain: an exact receipt-number lookup is the
        // actual operator need.
        $numeric = ctype_digit($term) ? (int) $term : null;

        $query->where(function (Builder $inner) use ($pattern, $numeric): void {
            $inner->where('receipts.ulid', 'ilike', $pattern)
                ->orWhereHas('invoice', function (Builder $invoice) use ($pattern): void {
                    $invoice->where('invoices.invoice_number', 'ilike', $pattern);
                });

            if ($numeric !== null) {
                $inner->orWhere('receipts.receipt_number', $numeric);
            }
        });
    }

    /** @return list<string> */
    protected function resultRelations(): array
    {
        return ['branch', 'invoice'];
    }

    /** @return list<string> */
    public function indexRelations(): array
    {
        return ['invoice'];
    }

    /** @return array<string, mixed> */
    public function indexDocumentFor(Model $model): array
    {
        if (! $model instanceof Receipt) {
            throw new RuntimeException('ReceiptSearchDefinition can only index a Receipt.');
        }

        return [
            'id' => $model->ulid,
            'merchant_id' => $model->merchant_id,
            'branch_id' => $model->branch_id,
            'receipt_number' => (string) $model->receipt_number,
            'invoice_number' => $model->invoice?->invoice_number,
            'reference' => $model->ulid,
        ];
    }

    protected function toResult(Model $model): SearchResultItem
    {
        return new SearchResultItem(
            type: $this->type(),
            ulid: $model->ulid,
            title: 'Receipt #'.$model->receipt_number,
            subtitle: $model->invoice?->invoice_number,
            status: null,
            date: $model->created_at?->toIso8601String(),
            amount: Money::ofMinor($model->amount_minor, Currency::from($model->currency)),
            routeName: 'front-office.receipts.detail',
            routeParamId: $model->ulid,
            branchUlid: $model->branch?->ulid,
            branchName: $model->branch?->name,
        );
    }
}
