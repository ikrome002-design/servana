<?php

declare(strict_types=1);

namespace App\Domain\Search\Definitions;

use App\Domain\Search\Contracts\SearchDocumentDefinition;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchSort;
use App\Domain\Search\Services\MeilisearchCandidateResolver;
use App\Domain\Search\Support\SearchLikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The shared, security-critical fetch flow for every indexed catalogue type (Phase 22).
 *
 * Three independent layers must agree before a row is returned
 * (`docs/architecture/search/search-catalogue.md` §4):
 *
 *   1. the ENGINE filter — applied before this class runs, in
 *      {@see MeilisearchCandidateResolver};
 *   2. the SQL filter — {@see baseQuery()} plus the model's own `BelongsToMerchant` /
 *      `BelongsToBranch` global scopes, so an out-of-scope engine candidate simply does not resolve;
 *   3. the per-record POLICY re-check — {@see passesRecheck()} calls the SAME policy ability the
 *      record's own detail route calls.
 *
 * Security therefore never depends on the search engine being correctly filtered. Layer 1 is
 * nevertheless mandatory: the defensive layers are IN ADDITION to engine filters, never instead of
 * them (Plan §68 forbids fetching unscoped and filtering afterwards).
 *
 * @template TModel of Model
 */
abstract class AbstractSearchDocumentDefinition implements SearchDocumentDefinition
{
    /**
     * How many rows to over-fetch relative to the requested page, so the per-record policy pass can
     * still fill the page after dropping records. Bounded so a crafted query can never widen the
     * read: the cap is 100 rows regardless of `limit`.
     */
    private const OVERFETCH_FACTOR = 5;

    private const OVERFETCH_CAP = 100;

    /**
     * The tenant-, branch- and (where applicable) own-scope-constrained base query. Implementations
     * pin `merchant_id` and the branch column EXPLICITLY rather than relying on the global scopes
     * alone, because this code also runs where a scope could be a no-op.
     *
     * @return Builder<TModel>
     */
    abstract protected function baseQuery(SearchContext $context): Builder;

    /**
     * Apply the type's allowlisted free-text match. Terms are escaped by
     * {@see SearchLikeTerm} and bound as parameters; no term is ever
     * interpolated into SQL.
     *
     * @param  Builder<TModel>  $query
     */
    abstract protected function applyTextMatch(Builder $query, string $term): void;

    /**
     * Map an already-authorized record to its safe result. Implementations may not read a contact
     * column: {@see SearchResultItem} has no field to put one in (decision D-22-03).
     *
     * @param  TModel  $model
     */
    abstract protected function toResult(Model $model): SearchResultItem;

    /** The physical table, used to qualify columns so a join can never make a column ambiguous. */
    abstract protected function table(): string;

    /**
     * Relations eager-loaded for the response. Keeps the result mapping free of N+1 (Plan §72).
     *
     * @return list<string>
     */
    protected function resultRelations(): array
    {
        return [];
    }

    /**
     * Per-record authorization. The default is the record's own detail-route policy ability, which
     * is the strongest available guarantee: a search result is authorized by exactly the check its
     * canonical page makes.
     *
     * @param  TModel  $model
     */
    protected function passesRecheck(SearchContext $context, Model $model): bool
    {
        return Gate::forUser($context->user)->allows('view', $model);
    }

    /** @return list<string> */
    public function indexRelations(): array
    {
        return [];
    }

    /**
     * @param  list<string>|null  $candidateUlids
     * @return list<SearchResultItem>
     */
    public function search(
        SearchContext $context,
        string $term,
        ?array $candidateUlids,
        SearchSort $sort,
        int $limit,
    ): array {
        // An empty branch set means "no reachable branch", never "all branches".
        if (! $context->hasBranchScope()) {
            return [];
        }

        // The engine ran and matched nothing: there is nothing to resolve. Returning here also
        // guarantees we never silently fall back to a broader PostgreSQL scan.
        if ($candidateUlids === []) {
            return [];
        }

        $query = $this->baseQuery($context);

        if ($candidateUlids === null) {
            $this->applyTextMatch($query, $term);
        } else {
            $query->whereIn($this->table().'.ulid', $candidateUlids);
        }

        $relations = $this->resultRelations();

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($sort === SearchSort::Recent) {
            $query->orderByDesc($this->table().'.created_at');
        }

        $records = $query->limit(min($limit * self::OVERFETCH_FACTOR, self::OVERFETCH_CAP))->get();

        if ($sort === SearchSort::Relevance && $candidateUlids !== null) {
            // Preserve the engine's ranking, which `whereIn` does not.
            $order = array_flip($candidateUlids);
            $records = $records
                ->sortBy(static fn (Model $model): int => $order[(string) $model->getAttribute('ulid')] ?? PHP_INT_MAX)
                ->values();
        }

        $items = [];

        /** @var TModel $record */
        foreach ($records as $record) {
            if (! $this->passesRecheck($context, $record)) {
                continue;
            }

            $items[] = $this->toResult($record);

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}
