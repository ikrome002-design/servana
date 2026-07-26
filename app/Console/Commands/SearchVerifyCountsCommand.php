<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Search\Contracts\SearchDocumentDefinition;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Services\SearchDocumentCatalogue;
use App\Domain\Search\Support\SearchEngineTasks;
use App\Domain\Search\Support\SearchIndexName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Meilisearch\Client;
use Throwable;

/**
 * Index drift check (`docs/architecture/search/search-indexing.md` §7).
 *
 * Compares each index's document count with the authoritative PostgreSQL row count and exits NON-ZERO
 * on any drift, so it is usable as a monitored check. It READS ONLY — it never repairs, because a
 * silent auto-repair would hide the cause of the drift. `servana:search-reindex` is the repair.
 *
 * SCHEDULING IS DELIBERATELY NOT WIRED HERE (decision D-22-05): queue topology, the scheduler and
 * scheduled reporting are Phase 21N's scope (Plan §80.1 `(17,18,20D-W) → 21N`), and 21N is blocked
 * behind External Gate W. Phase 22 ships the command; 21N owns its scheduled invocation.
 */
final class SearchVerifyCountsCommand extends Command
{
    protected $signature = 'servana:search-verify-counts
        {--type=* : Catalogue document type(s) to verify; omit for every indexed type}';

    protected $description = 'Report search-index drift against PostgreSQL (read-only; non-zero exit on drift).';

    public function handle(SearchDocumentCatalogue $catalogue): int
    {
        $client = $this->client();

        if ($client === null) {
            $this->error('Scout is not configured for Meilisearch; there is no index to verify.');

            return self::FAILURE;
        }

        $definitions = $this->selectedDefinitions($catalogue);

        if ($definitions === null) {
            return self::FAILURE;
        }

        $rows = [];
        $drifted = false;

        foreach ($definitions as $definition) {
            $indexName = $definition->indexName();

            if ($indexName === null) {
                continue;
            }

            $modelClass = $definition->modelClass();
            $expected = $modelClass::query()->count();
            $physicalIndex = SearchIndexName::prefixed($indexName);

            try {
                // Meilisearch indexing is ASYNC, so a count taken while tasks are still queued would
                // report drift that does not exist — most obviously right after a backfill. Settle
                // first, so a reported difference is real drift rather than a race.
                SearchEngineTasks::settle($client, $physicalIndex);

                $stats = $client->index($physicalIndex)->stats();
                /** @var int $actual */
                $actual = $stats['numberOfDocuments'] ?? 0;
            } catch (Throwable $exception) {
                $rows[] = [$definition->type()->value, (string) $expected, 'unreachable', $exception::class];
                $drifted = true;

                continue;
            }

            $missing = max(0, $expected - $actual);
            $excess = max(0, $actual - $expected);

            if ($missing !== 0 || $excess !== 0) {
                $drifted = true;
            }

            $rows[] = [
                $definition->type()->value,
                (string) $expected,
                (string) $actual,
                $missing === 0 && $excess === 0
                    ? 'in sync'
                    : sprintf('missing %d, excess %d', $missing, $excess),
            ];
        }

        $this->table(['type', 'postgres', 'index', 'drift'], $rows);

        if ($drifted) {
            $this->error('Search index drift detected. Run `php artisan servana:search-reindex` to converge.');

            return self::FAILURE;
        }

        $this->info('All search indexes are in sync with PostgreSQL.');

        return self::SUCCESS;
    }

    /**
     * @return list<SearchDocumentDefinition>|null
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
                $this->error(sprintf('Unknown or non-indexed document type "%s".', $value));

                return null;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    private function client(): ?Client
    {
        if (Config::get('scout.driver') !== 'meilisearch') {
            return null;
        }

        $host = Config::get('scout.meilisearch.host');
        $key = Config::get('scout.meilisearch.key');

        if (! is_string($host) || $host === '') {
            return null;
        }

        return new Client($host, is_string($key) && $key !== '' ? $key : null);
    }
}
