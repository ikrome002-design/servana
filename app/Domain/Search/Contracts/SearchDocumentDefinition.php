<?php

declare(strict_types=1);

namespace App\Domain\Search\Contracts;

use App\Domain\Search\Definitions\AbstractSearchDocumentDefinition;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Enums\SearchSort;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the Phase 22 search catalogue, in executable form
 * (`docs/architecture/search/search-catalogue.md` §3).
 *
 * A definition owns EVERYTHING about its type: which existing authority admits it, how its
 * tenant/branch/own scope is expressed in SQL, what its index document may contain, and what a
 * result may reveal. Nothing about a type lives anywhere else, so a type cannot be half-secured.
 *
 * The interface is deliberately non-generic and never hands a `Builder` or a typed model across a
 * boundary — each concrete definition keeps its query and its model inside itself (via the generic
 * {@see AbstractSearchDocumentDefinition}), which is what lets the
 * catalogue hold a heterogeneous collection while every query stays precisely typed.
 */
interface SearchDocumentDefinition
{
    public function type(): SearchDocumentType;

    /**
     * The UNPREFIXED Meilisearch index name, or null when the type is not indexed at all
     * (`served_client` — decision D-22-06). Scout resolves `config('scout.prefix')`.
     */
    public function indexName(): ?string;

    /** @return class-string<Model> */
    public function modelClass(): string;

    /**
     * Whether this caller may search this type AT ALL, using only permissions that already exist
     * (decision D-22-01). False means the type is silently excluded from the request — never a 403,
     * which would be an existence oracle for the catalogue.
     */
    public function canSearch(SearchContext $context): bool;

    /**
     * Execute the type's own scoped search and return only already-authorized results.
     *
     * @param  list<string>|null  $candidateUlids  engine-supplied candidates in relevance order, or
     *                                             null to text-match in PostgreSQL instead
     * @return list<SearchResultItem>
     */
    public function search(
        SearchContext $context,
        string $term,
        ?array $candidateUlids,
        SearchSort $sort,
        int $limit,
    ): array;

    /**
     * The allowlisted index document for one record. Every key is named explicitly: no `toArray()`,
     * no attribute spread, no loop over `$fillable`, so a new column can never be indexed by
     * accident.
     *
     * @return array<string, mixed>
     */
    public function indexDocumentFor(Model $model): array;

    /**
     * Relations to eager-load before building index documents, so no lazy load happens inside a
     * queued job (where no tenant context is bound and the global scopes are no-ops).
     *
     * @return list<string>
     */
    public function indexRelations(): array;
}
