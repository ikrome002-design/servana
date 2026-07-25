<?php

declare(strict_types=1);

namespace App\Domain\Search\Definitions;

use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Support\SearchLikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `service_session` — branch-scoped session search by reference, client name and service name
 * (Phase 22). Authority is `ServiceSessionPolicy::viewAny` (= `service_session.view`).
 *
 * TARGET ROUTE. The SPA has no service-session DETAIL screen (`front-office.sessions` is a
 * list-only page), so a result targets that list. Phase 22 does not create a detail screen, because
 * the phase's scope is search and not new business-workflow screens.
 *
 * `notes` is deliberately neither indexed nor returned: it is operator free text about a client's
 * service, which is exactly the kind of content that must not become branch-wide searchable.
 *
 * @extends AbstractSearchDocumentDefinition<ServiceSession>
 */
final class ServiceSessionSearchDefinition extends AbstractSearchDocumentDefinition
{
    public function type(): SearchDocumentType
    {
        return SearchDocumentType::ServiceSession;
    }

    public function indexName(): string
    {
        return 'service_sessions';
    }

    public function modelClass(): string
    {
        return ServiceSession::class;
    }

    public function canSearch(SearchContext $context): bool
    {
        return $context->can('service_session.view');
    }

    protected function table(): string
    {
        return 'service_sessions';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return ServiceSession::query()
            ->where('service_sessions.merchant_id', $context->merchantId)
            ->whereIn('service_sessions.branch_id', $context->branchIds);
    }

    protected function applyTextMatch(Builder $query, string $term): void
    {
        $pattern = SearchLikeTerm::contains($term);

        $query->where(function (Builder $inner) use ($pattern): void {
            $inner->where('service_sessions.ulid', 'ilike', $pattern)
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
        if (! $model instanceof ServiceSession) {
            throw new RuntimeException('ServiceSessionSearchDefinition can only index a ServiceSession.');
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
            title: $model->service->name ?? 'Service session',
            subtitle: $model->client?->full_name,
            status: $model->status->value,
            date: ($model->started_at ?? $model->created_at)?->toIso8601String(),
            amount: null,
            routeName: 'front-office.sessions',
            routeParamId: null,
            branchUlid: $model->branch?->ulid,
            branchName: $model->branch?->name,
        );
    }
}
