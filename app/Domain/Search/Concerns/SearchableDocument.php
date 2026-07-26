<?php

declare(strict_types=1);

namespace App\Domain\Search\Concerns;

use App\Domain\Search\Contracts\SearchDocumentDefinition;
use App\Domain\Search\Services\SearchDocumentCatalogue;
use App\Domain\Search\Support\SearchIndexName;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Searchable;
use RuntimeException;

/**
 * Makes a model searchable WITHOUT putting any search knowledge in the model (Phase 22).
 *
 * A model gains search by adding this one trait; everything about *what* is indexed stays in its
 * catalogue definition ({@see SearchDocumentDefinition}). That is the point: the field allowlist
 * lives in exactly one auditable place, so adding a column to a table can never silently add it to a
 * search index, and a reviewer never has to check eight models to know what is exposed.
 *
 * The document id is the public ULID, not the autoincrement primary key — the same identifier the
 * API and the SPA use, so an index document can never leak an internal id.
 */
trait SearchableDocument
{
    use Searchable;

    /** The physical index, resolved through the one prefixing helper Scout and search both use. */
    public function searchableAs(): string
    {
        $indexName = $this->searchDefinition()->indexName();

        if ($indexName === null) {
            throw new RuntimeException(static::class.' has no search index name.');
        }

        return SearchIndexName::prefixed($indexName);
    }

    /**
     * The allowlisted document. Delegates to the definition, which names every key explicitly.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return $this->searchDefinition()->indexDocumentFor($this);
    }

    /** Public ULID as the engine's primary key — never the internal autoincrement id. */
    public function getScoutKey(): string
    {
        return (string) $this->getAttribute('ulid');
    }

    /**
     * `id`, deliberately — NOT `ulid`.
     *
     * Scout composes the document as `[getScoutKeyName() => getScoutKey()] + toSearchableArray()`.
     * Naming the key `ulid` would therefore add a SECOND identifier attribute to every document
     * alongside the `id` the builders already emit, silently breaking the "a document contains
     * exactly its declared keys" invariant that `SearchIndexDocumentTest` and
     * `SearchEngineIntegrationTest` enforce. Naming it `id` makes Scout's key and the builder's key
     * the same attribute with the same value.
     *
     * This is safe because Servana never resolves results through Scout's model mapping (which is
     * the only place Scout treats this as a database COLUMN): the candidate resolver returns ids and
     * `AbstractSearchDocumentDefinition` re-resolves them with an explicit `whereIn(..., 'ulid')`
     * against a tenant-scoped query.
     */
    public function getScoutKeyName(): string
    {
        return 'id';
    }

    /**
     * Eager-load the relations the document needs BEFORE building it, so no lazy load happens inside
     * a queued index job — where no tenant context is bound and the global scopes are no-ops.
     *
     * @param  Collection<int, static>  $models
     * @return Collection<int, static>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        $relations = $this->searchDefinition()->indexRelations();

        if ($relations === []) {
            return $models;
        }

        return $models->load($relations);
    }

    private function searchDefinition(): SearchDocumentDefinition
    {
        $definition = app(SearchDocumentCatalogue::class)->forModel(static::class);

        if ($definition === null) {
            throw new RuntimeException(static::class.' is not registered in the search catalogue.');
        }

        return $definition;
    }
}
