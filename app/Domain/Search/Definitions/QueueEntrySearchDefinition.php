<?php

declare(strict_types=1);

namespace App\Domain\Search\Definitions;

use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Support\SearchLikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `queue_entry` — branch-scoped queue search by reference, client name and service name (Phase 22).
 *
 * Authority is `QueueEntryPolicy::viewAny` (`queue.view` OR `branch.dashboard.view`), matching the
 * live queue list and detail routes.
 *
 * Every free-text operator field on this table (`cancellation_reason`, `transfer_reason`,
 * `preferred_personnel_override_reason`, `estimated_wait_override_reason`) is excluded from both the
 * index and the result: they are internal justifications, not identifiers, and indexing them would
 * make operator notes searchable across a branch.
 *
 * @extends AbstractSearchDocumentDefinition<QueueEntry>
 */
final class QueueEntrySearchDefinition extends AbstractSearchDocumentDefinition
{
    public function type(): SearchDocumentType
    {
        return SearchDocumentType::QueueEntry;
    }

    public function indexName(): string
    {
        return 'queue_entries';
    }

    public function modelClass(): string
    {
        return QueueEntry::class;
    }

    public function canSearch(SearchContext $context): bool
    {
        return $context->can('queue.view') || $context->can('branch.dashboard.view');
    }

    protected function table(): string
    {
        return 'queue_entries';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return QueueEntry::query()
            ->where('queue_entries.merchant_id', $context->merchantId)
            ->whereIn('queue_entries.branch_id', $context->branchIds);
    }

    protected function applyTextMatch(Builder $query, string $term): void
    {
        $pattern = SearchLikeTerm::contains($term);

        $query->where(function (Builder $inner) use ($pattern): void {
            $inner->where('queue_entries.ulid', 'ilike', $pattern)
                ->orWhereHas('client', function (Builder $client) use ($pattern): void {
                    $client->where('clients.full_name', 'ilike', $pattern);
                })
                ->orWhereHas('service', function (Builder $service) use ($pattern): void {
                    $service->where('services.name', 'ilike', $pattern);
                });
        });
    }

    /** @return list<string> */
    protected function resultRelations(): array
    {
        return ['branch', 'client', 'service'];
    }

    /** @return list<string> */
    public function indexRelations(): array
    {
        return ['client', 'service'];
    }

    /** @return array<string, mixed> */
    public function indexDocumentFor(Model $model): array
    {
        if (! $model instanceof QueueEntry) {
            throw new RuntimeException('QueueEntrySearchDefinition can only index a QueueEntry.');
        }

        return [
            'id' => $model->ulid,
            'merchant_id' => $model->merchant_id,
            'branch_id' => $model->branch_id,
            'reference' => $model->ulid,
            'client_name' => $model->client?->full_name,
            'service_name' => $model->service?->name,
        ];
    }

    protected function toResult(Model $model): SearchResultItem
    {
        return new SearchResultItem(
            type: $this->type(),
            ulid: $model->ulid,
            title: $model->service->name ?? 'Queue entry',
            subtitle: $model->client?->full_name,
            status: $model->status->value,
            date: $model->queued_at->toIso8601String(),
            amount: null,
            routeName: 'front-office.queue-entry',
            routeParamId: $model->ulid,
            branchUlid: $model->branch?->ulid,
            branchName: $model->branch?->name,
        );
    }
}
