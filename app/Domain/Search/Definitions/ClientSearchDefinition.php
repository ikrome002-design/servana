<?php

declare(strict_types=1);

namespace App\Domain\Search\Definitions;

use App\Domain\Clients\Models\Client;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Services\ClientPhoneLookup;
use App\Domain\Search\Support\SearchLikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `client` — branch-scoped client search by NAME (Phase 22).
 *
 * Authority mirrors the live client list exactly: `ClientController::index` treats *searching* as a
 * capability distinct from *listing*, aborting 403 on `q` without `front_office.search`. Search
 * honours that split, so a role that may list clients but not search them gets no `client` results.
 *
 * CONTACT PROTECTION (ADR-010; Plan §74). The index document carries the name and the tenancy pair
 * and nothing else — no `phone_encrypted`, no `email_encrypted`, no `phone_index`, no
 * `phone_last_four`, and no `notes` (operator free text). The result carries no contact field
 * either, because {@see SearchResultItem} has none. Exact phone lookup is a SEPARATE server-side
 * path through the existing keyed blind index and never touches this index
 * ({@see ClientPhoneLookup}).
 *
 * @extends AbstractSearchDocumentDefinition<Client>
 */
final class ClientSearchDefinition extends AbstractSearchDocumentDefinition
{
    public function type(): SearchDocumentType
    {
        return SearchDocumentType::Client;
    }

    public function indexName(): string
    {
        return 'clients';
    }

    public function modelClass(): string
    {
        return Client::class;
    }

    public function canSearch(SearchContext $context): bool
    {
        // ClientPolicy::viewAny is `client.view`; `front_office.search` is the search capability.
        return $context->can('client.view') && $context->can('front_office.search');
    }

    protected function table(): string
    {
        return 'clients';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return Client::query()
            ->where('clients.merchant_id', $context->merchantId)
            ->whereIn('clients.branch_id', $context->branchIds);
    }

    protected function applyTextMatch(Builder $query, string $term): void
    {
        $query->where('clients.full_name', 'ilike', SearchLikeTerm::contains($term));
    }

    /** @return list<string> */
    protected function resultRelations(): array
    {
        return ['branch'];
    }

    /** @return array<string, mixed> */
    public function indexDocumentFor(Model $model): array
    {
        if (! $model instanceof Client) {
            throw new RuntimeException('ClientSearchDefinition can only index a Client.');
        }

        return [
            'id' => $model->ulid,
            'merchant_id' => $model->merchant_id,
            'branch_id' => $model->branch_id,
            'full_name' => $model->full_name,
        ];
    }

    /**
     * The exact-phone path ({@see ClientPhoneLookup}) resolves its own
     * already-authorized rows, so it needs the same mapping without going through the text-search
     * flow. Exposed narrowly for that one caller rather than making {@see toResult()} public.
     */
    public function resultFor(Client $client): SearchResultItem
    {
        return $this->toResult($client);
    }

    protected function toResult(Model $model): SearchResultItem
    {
        return new SearchResultItem(
            type: $this->type(),
            ulid: $model->ulid,
            title: $model->full_name,
            subtitle: null,
            status: $model->status->value,
            date: $model->created_at?->toIso8601String(),
            amount: null,
            routeName: 'front-office.client-detail',
            routeParamId: $model->ulid,
            branchUlid: $model->branch?->ulid,
            branchName: $model->branch?->name,
        );
    }
}
