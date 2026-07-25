<?php

declare(strict_types=1);

use App\Console\Commands\SearchReindexCommand;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Clients\Models\Client;
use App\Domain\Search\Support\SearchIndexName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\DocumentsQuery;

uses(RefreshDatabase::class)->group('search', 'phase22', 'search-engine');

/*
 |==============================================================================
 | servana:search-reindex and servana:search-verify-counts (search-indexing.md §6–§7).
 |
 | Backfill is idempotent, chunked, forward-only and non-destructive; the drift
 | check reads only and exits non-zero on drift so it is usable as a monitored
 | check. Both are exercised against the REAL engine.
 |==============================================================================
 */

function commandEngineClient(): MeilisearchClient
{
    /** @var string $host */
    $host = Config::get('scout.meilisearch.host');
    /** @var string|null $key */
    $key = Config::get('scout.meilisearch.key');

    return new MeilisearchClient($host, is_string($key) && $key !== '' ? $key : null);
}

function commandIndexDocumentCount(string $index): int
{
    $client = commandEngineClient();
    $task = $client->index($index)->updateFilterableAttributes(['merchant_id', 'branch_id']);
    $client->waitForTask($task['taskUid'], P22_TASK_TIMEOUT_MS);

    return count($client->index($index)->getDocuments((new DocumentsQuery)->setLimit(200))->getResults());
}

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);

    Config::set('scout.driver', 'meilisearch');
    Config::set('scout.prefix', 'servana_p22cmd_'.Str::lower((string) Str::ulid()).'_');
    Config::set('scout.queue', false);

    try {
        commandEngineClient()->health();
    } catch (Throwable $exception) {
        $this->fail('Meilisearch is not reachable; the Phase 22 command tests require it.');
    }
});

afterEach(function (): void {
    $client = commandEngineClient();
    $prefix = (string) Config::get('scout.prefix');

    // Best-effort: teardown must never fail an otherwise-passing test, and a delete that is still
    // queued when the run ends is harmless because the prefix is unique per test.
    try {
        foreach ($client->getIndexes()->getResults() as $index) {
            if (str_starts_with($index->getUid(), $prefix)) {
                $client->deleteIndex($index->getUid());
            }
        }
    } catch (Throwable) {
        // ignored
    }
});

it('backfills every indexed type and reports a per-type count', function (): void {
    searchScenario();

    $this->artisan('servana:search-reindex', ['--chunk' => 1])
        ->assertSuccessful()
        ->expectsOutputToContain('client:')
        ->expectsOutputToContain('staff:')
        ->expectsOutputToContain('appointment:')
        ->expectsOutputToContain('queue_entry:')
        ->expectsOutputToContain('service_session:')
        ->expectsOutputToContain('invoice:')
        ->expectsOutputToContain('receipt:');
});

it('is idempotent — a second run converges instead of duplicating', function (): void {
    searchScenario();
    $index = SearchIndexName::prefixed('clients');

    $this->artisan('servana:search-reindex', ['--type' => ['client']])->assertSuccessful();
    $first = commandIndexDocumentCount($index);

    $this->artisan('servana:search-reindex', ['--type' => ['client']])->assertSuccessful();
    $second = commandIndexDocumentCount($index);

    // Meilisearch upserts by primary key (the ULID), so re-running converges.
    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe($first);
});

it('chunks the backfill, so memory stays flat on a large tenant', function (): void {
    $scn = searchScenario();

    // Enough rows to force several chunks at a chunk size of 2.
    Client::factory()->count(5)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
    ]);

    $this->artisan('servana:search-reindex', ['--type' => ['client'], '--chunk' => 2])
        ->assertSuccessful();

    $expected = Client::query()->count();

    expect(commandIndexDocumentCount(SearchIndexName::prefixed('clients')))->toBe($expected);
});

it('syncs index settings first, so a fresh index can actually be filtered', function (): void {
    $scn = searchScenario();

    $this->artisan('servana:search-reindex', ['--type' => ['client']])->assertSuccessful();

    $settings = commandEngineClient()->index(SearchIndexName::prefixed('clients'))->getSettings();

    expect($settings['filterableAttributes'])->toEqualCanonicalizing(['merchant_id', 'branch_id']);

    // And the endpoint therefore works immediately after a backfill, with no extra step.
    $response = search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['client']])->assertOk();

    expect(searchResultUlids($response, 'client'))->toContain($scn['clientA']->ulid);
});

it('indexes every tenant row, each carrying its own merchant id', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    // The command runs OUTSIDE a request, so the global scopes are no-ops and it indexes all
    // tenants — which is correct, because the per-query filter is what separates them.
    $this->artisan('servana:search-reindex', ['--type' => ['client']])->assertSuccessful();

    $documents = commandEngineClient()
        ->index(SearchIndexName::prefixed('clients'))
        ->getDocuments((new DocumentsQuery)->setLimit(200))
        ->getResults();

    $merchantIds = array_values(array_unique(array_map(
        static fn (array $document): int => (int) $document['merchant_id'],
        $documents,
    )));

    expect($merchantIds)->toContain($scn['merchant']->id)
        ->and($merchantIds)->toContain($foreign['merchant']->id);
});

it('rejects an unknown or non-indexed document type', function (string $type): void {
    $this->artisan('servana:search-reindex', ['--type' => [$type]])->assertFailed();
})->with([
    'unknown' => 'wallet_payment',
    // served_client is a real catalogue type but is deliberately never indexed (D-22-06).
    'non-indexed' => 'served_client',
]);

it('offers no destructive flag, so an index can never be dropped by a backfill', function (): void {
    $definition = app(SearchReindexCommand::class)->getDefinition();
    $optionNames = array_keys($definition->getOptions());
    // Comments stripped: the command's docblock legitimately DOCUMENTS that it never flushes.
    $source = phpCodeWithoutComments(
        (string) file_get_contents(base_path('app/Console/Commands/SearchReindexCommand.php')),
    );

    // Documented forward-only strategy (search-indexing.md §6): no --fresh, no --flush, no
    // implicit `scout:flush`, and no index deletion anywhere in the command.
    expect($definition->hasOption('fresh'))->toBeFalse()
        ->and($definition->hasOption('flush'))->toBeFalse()
        ->and($optionNames)->toContain('type')
        ->and($optionNames)->toContain('chunk')
        ->and($source)->not->toContain('scout:flush')
        ->and($source)->not->toContain('deleteIndex')
        ->and($source)->not->toContain('unsearchable');
});

/*
 |--------------------------------------------------------------------------
 | Drift verification
 |--------------------------------------------------------------------------
 */

it('reports every index in sync straight after a backfill', function (): void {
    searchScenario();

    $this->artisan('servana:search-reindex')->assertSuccessful();

    $this->artisan('servana:search-verify-counts')
        ->assertSuccessful()
        ->expectsOutputToContain('in sync');
});

it('detects a MISSING document and exits non-zero', function (): void {
    $scn = searchScenario();

    $this->artisan('servana:search-reindex', ['--type' => ['client']])->assertSuccessful();

    // Remove one document from the index behind the command's back.
    $client = commandEngineClient();
    $index = SearchIndexName::prefixed('clients');
    $task = $client->index($index)->deleteDocument($scn['clientA']->ulid);
    $client->waitForTask($task['taskUid'], P22_TASK_TIMEOUT_MS);

    $this->artisan('servana:search-verify-counts', ['--type' => ['client']])
        ->assertFailed()
        ->expectsOutputToContain('missing');
});

it('detects an EXCESS document and exits non-zero', function (): void {
    searchScenario();

    $this->artisan('servana:search-reindex', ['--type' => ['client']])->assertSuccessful();

    $client = commandEngineClient();
    $index = SearchIndexName::prefixed('clients');
    $task = $client->index($index)->addDocuments([[
        'id' => 'ORPHANDOCUMENTULID',
        'merchant_id' => 1,
        'branch_id' => 1,
        'full_name' => 'Orphan Row',
    ]], 'id');
    $client->waitForTask($task['taskUid'], P22_TASK_TIMEOUT_MS);

    $this->artisan('servana:search-verify-counts', ['--type' => ['client']])
        ->assertFailed()
        ->expectsOutputToContain('excess');
});

it('never repairs drift, because a silent auto-repair would hide the cause', function (): void {
    $scn = searchScenario();

    $this->artisan('servana:search-reindex', ['--type' => ['client']])->assertSuccessful();

    $client = commandEngineClient();
    $index = SearchIndexName::prefixed('clients');
    $task = $client->index($index)->deleteDocument($scn['clientA']->ulid);
    $client->waitForTask($task['taskUid'], P22_TASK_TIMEOUT_MS);

    $before = commandIndexDocumentCount($index);
    $this->artisan('servana:search-verify-counts', ['--type' => ['client']])->assertFailed();

    expect(commandIndexDocumentCount($index))->toBe($before);
});

it('rejects an unknown type for the drift check too', function (): void {
    $this->artisan('servana:search-verify-counts', ['--type' => ['wallet_payment']])->assertFailed();
});
