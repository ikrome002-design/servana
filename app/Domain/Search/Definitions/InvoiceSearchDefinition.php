<?php

declare(strict_types=1);

namespace App\Domain\Search\Definitions;

use App\Domain\Invoicing\Models\Invoice;
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
 * `invoice` — branch-scoped invoice search by invoice number, reference and client name (Phase 22).
 * Authority is `InvoicePolicy::viewAny` (= `invoice.view`), the same key the live invoice list and
 * detail routes require.
 *
 * The amount is the invoice TOTAL as integer minor units through the {@see Money} value object
 * (ADR-005) — never a float, and never computed in the browser. `InvoiceResource` already exposes
 * the total to every `invoice.view` holder, so this is not a new exposure; search simply omits the
 * rest (subtotal, discount, tax, preferred-personnel fee, platform-fee snapshot, validated paid,
 * balance).
 *
 * EXCLUDED from both index and result: `percentage_fee_config_snapshot` (internal fee
 * configuration), `void_reason` and `adjustment_reason` (operator free text), client contact of any
 * kind, and every Wallet/provider field — none of which exist yet and none of which may be added
 * here when they do (ADR-012; Plan §9 rule 20).
 *
 * @extends AbstractSearchDocumentDefinition<Invoice>
 */
final class InvoiceSearchDefinition extends AbstractSearchDocumentDefinition
{
    public function type(): SearchDocumentType
    {
        return SearchDocumentType::Invoice;
    }

    public function indexName(): string
    {
        return 'invoices';
    }

    public function modelClass(): string
    {
        return Invoice::class;
    }

    public function canSearch(SearchContext $context): bool
    {
        return $context->can('invoice.view');
    }

    protected function table(): string
    {
        return 'invoices';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return Invoice::query()
            ->where('invoices.merchant_id', $context->merchantId)
            ->whereIn('invoices.branch_id', $context->branchIds);
    }

    protected function applyTextMatch(Builder $query, string $term): void
    {
        $pattern = SearchLikeTerm::contains($term);

        $query->where(function (Builder $inner) use ($pattern): void {
            $inner->where('invoices.invoice_number', 'ilike', $pattern)
                ->orWhere('invoices.ulid', 'ilike', $pattern)
                ->orWhereHas('client', function (Builder $client) use ($pattern): void {
                    $client->where('clients.full_name', 'ilike', $pattern);
                });
        });
    }

    /** @return list<string> */
    protected function resultRelations(): array
    {
        return ['branch', 'client'];
    }

    /** @return list<string> */
    public function indexRelations(): array
    {
        return ['client'];
    }

    /** @return array<string, mixed> */
    public function indexDocumentFor(Model $model): array
    {
        if (! $model instanceof Invoice) {
            throw new RuntimeException('InvoiceSearchDefinition can only index an Invoice.');
        }

        return [
            'id' => $model->ulid,
            'merchant_id' => $model->merchant_id,
            'branch_id' => $model->branch_id,
            'invoice_number' => $model->invoice_number,
            'reference' => $model->ulid,
            'client_name' => $model->client?->full_name,
        ];
    }

    protected function toResult(Model $model): SearchResultItem
    {
        return new SearchResultItem(
            type: $this->type(),
            // A draft invoice has no number yet (it is allocated at draft → issued).
            ulid: $model->ulid,
            title: $model->invoice_number ?? 'Draft invoice',
            subtitle: $model->client?->full_name,
            status: $model->status->value,
            date: $model->created_at?->toIso8601String(),
            amount: Money::ofMinor($model->total_minor, Currency::from($model->currency)),
            routeName: 'front-office.invoices.detail',
            routeParamId: $model->ulid,
            branchUlid: $model->branch?->ulid,
            branchName: $model->branch?->name,
        );
    }
}
