<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Search\Contracts\SearchDocumentDefinition;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Services\SearchDocumentCatalogue;
use App\Domain\Search\Support\SearchEngineTasks;
use App\Domain\Search\Support\SearchIndexName;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Meilisearch\Client as MeilisearchClient;

/**
 * Backfill the Phase 22 search indexes (`docs/architecture/search/search-indexing.md` §6).
 *
 * IDEMPOTENT — Meilisearch upserts by primary key (the public ULID), so re-running converges rather
 * than duplicating.
 *
 * CHUNKED — streamed with `chunkById`, relations eager-loaded per chunk, so memory stays flat on a
 * large tenant and no lazy load happens while building documents.
 *
 * FORWARD-ONLY AND NON-DESTRUCTIVE — it never deletes or recreates an index. There is deliberately no
 * `--fresh` and no implicit `scout:flush`: a stale document is corrected by the upsert or reported by
 * `servana:search-verify-counts`, and no index is ever dropped in an environment serving traffic.
 * Meilisearch v1.10 has no alias primitive, so forward-only upsert IS the documented strategy.
 *
 * It runs OUTSIDE a request, so `MerchantScope`/`BranchScope` are no-ops (they apply only when a
 * merchant is resolved) and it indexes every tenant's rows — each document carrying its own
 * `merchant_id`, which is exactly what makes the per-query tenancy filter work.
 */
final class SearchReindexCommand extends Command
{
    protected $signature = 'servana:search-reindex
        {--type=* : Catalogue document type(s) to reindex; omit for every indexed type}
        {--chunk=250 : Rows per batch}';

    protected $description = 'Backfill the search indexes (idempotent, chunked, forward-only).';

    public function handle(SearchDocumentCatalogue $catalogue): int
    {
        $definitions = $this->selectedDefinitions($catalogue);

        if ($definitions === null) {
            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));

        // Settings FIRST, always. An index without its `filterableAttributes` rejects the mandatory
        // `merchant_id`/`branch_id` filter outright, and the resolver then degrades to "no
        // candidates" — so a freshly created index that was populated but never configured produces
        // a search that silently returns nothing. Syncing here (idempotent) makes that state
        // unreachable through this command.
        $this->call('scout:sync-index-settings');

        foreach ($definitions as $definition) {
            $this->reindex($definition, $chunk);
        }

        // Meilisearch applies documents ASYNCHRONOUSLY, so returning here without waiting would
        // report success while the documents were still queued — an operator who ran a backfill and
        // immediately searched would get nothing and reasonably conclude the backfill had failed.
        // Bounded, so a stuck queue surfaces as a slow command rather than a hang.
        $this->settleIndexes($definitions);

        return self::SUCCESS;
    }

    /**
     * Wait for every touched index to finish its queued work, and say so if one does not.
     *
     * @param  list<SearchDocumentDefinition>  $definitions
     */
    private function settleIndexes(array $definitions): void
    {
        $client = $this->engineClient();

        if ($client === null) {
            return; // no engine to wait on (the `null` driver is a no-op by design)
        }

        foreach ($definitions as $definition) {
            $indexName = $definition->indexName();

            if ($indexName === null) {
                continue;
            }

            if (! SearchEngineTasks::settle($client, SearchIndexName::prefixed($indexName))) {
                $this->warn(sprintf(
                    '%s: the index was still applying documents after %ds — run servana:search-verify-counts to confirm.',
                    $definition->type()->value,
                    (int) SearchEngineTasks::DEFAULT_TIMEOUT_SECONDS,
                ));
            }
        }
    }

    /** The engine client, or null when Scout is not pointed at Meilisearch. */
    private function engineClient(): ?MeilisearchClient
    {
        if (Config::get('scout.driver') !== 'meilisearch') {
            return null;
        }

        $host = Config::get('scout.meilisearch.host');
        $key = Config::get('scout.meilisearch.key');

        if (! is_string($host) || $host === '') {
            return null;
        }

        return new MeilisearchClient($host, is_string($key) && $key !== '' ? $key : null);
    }

    /**
     * @return list<SearchDocumentDefinition>|null null signals an invalid `--type`
     */
    private function selectedDefinitions(SearchDocumentCatalogue $catalogue): ?array
    {
        /** @var list<string> $requested */
        $requested = (array) $this->option('type');

        if ($requested === []) {
            return $catalogue->indexed();
        }

        $definitions = [];

        foreach ($requested as $value) {
            $type = SearchDocumentType::tryFrom($value);
            $definition = $type === null ? null : $catalogue->for($type);

            if ($definition === null || $definition->indexName() === null) {
                $this->error(sprintf(
                    'Unknown or non-indexed document type "%s". Indexed types: %s.',
                    $value,
                    implode(', ', array_map(
                        static fn (SearchDocumentDefinition $d): string => $d->type()->value,
                        $catalogue->indexed(),
                    )),
                ));

                return null;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    private function reindex(SearchDocumentDefinition $definition, int $chunk): void
    {
        $modelClass = $definition->modelClass();
        $relations = $definition->indexRelations();
        $indexed = 0;

        $query = $modelClass::query();

        if ($relations !== []) {
            $query->with($relations);
        }

        $engine = $this->engine();

        $query->chunkById($chunk, function (Collection $models) use ($engine, &$indexed): void {
            if ($models->isEmpty()) {
                return;
            }

            // ONE engine call per chunk rather than one per row: the upsert goes through each
            // model's toSearchableArray(), which delegates to the catalogue definition's allowlist.
            $engine->update($models);

            $indexed += $models->count();
        });

        $this->info(sprintf('%s: %d document(s) upserted.', $definition->type()->value, $indexed));
    }

    /**
     * Scout's configured engine. Using the engine contract directly (rather than the collection
     * macro) keeps the batch call statically typed and makes the `null` driver an explicit no-op
     * instead of a special case.
     */
    private function engine(): Engine
    {
        return app(EngineManager::class)->engine();
    }
}
