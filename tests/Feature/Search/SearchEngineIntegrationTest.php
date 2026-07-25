<?php

declare(strict_types=1);

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
 | REAL Meilisearch integration (search-indexing.md §2; decision D-22-02).
 |
 | The rest of the suite runs on Scout's NullEngine, so the AUTHORITY layer is
 | proven deterministically against PostgreSQL. This file is the counterpart: it
 | proves the ENGINE-side behaviour Phase 22 depends on — that the tenancy filter
 | is applied by the engine itself, that a crafted term cannot escape it, that no
 | sensitive field ever reaches the index, and that a POISONED index still cannot
 | leak, because PostgreSQL and the policies are the authority.
 |
 | It REQUIRES a reachable engine and FAILS (never skips) without one: the CI
 | Backend job runs a getmeili/meilisearch:v1.10 service for exactly this reason,
 | and the dev stack already runs one. A silent skip here would be a zero-test
 | command reported as green.
 |
 | NOTE on fixtures: with `scout.driver = meilisearch` the Scout observers are LIVE,
 | so every client the scenario helpers create is indexed automatically. Tests that
 | need a controlled index therefore use names the scenario rows do not share.
 |==============================================================================
 */

function engineClient(): MeilisearchClient
{
    /** @var string $host */
    $host = Config::get('scout.meilisearch.host');
    /** @var string|null $key */
    $key = Config::get('scout.meilisearch.key');

    return new MeilisearchClient($host, is_string($key) && $key !== '' ? $key : null);
}

/**
 * Apply the real index settings, then push documents and wait — the same order the reindex command
 * uses, and the reason it does: without `filterableAttributes` the mandatory tenancy filter is
 * rejected and search silently returns nothing.
 *
 * @param  list<array<string, mixed>>  $documents
 */
function engineIndex(MeilisearchClient $client, string $index, array $documents): void
{
    $client->index($index)->updateFilterableAttributes(['merchant_id', 'branch_id']);

    $task = $client->index($index)->addDocuments($documents, 'id');
    $client->waitForTask($task['taskUid'], P22_TASK_TIMEOUT_MS);
}

/**
 * Force a synchronization point. Meilisearch indexing is ASYNC and Scout does not wait, so a read
 * immediately after `searchable()` can hit an index that does not exist yet. Tasks are processed in
 * order per index, so waiting for a later task guarantees the earlier writes have landed.
 */
function engineSettle(MeilisearchClient $client, string $index): void
{
    $task = $client->index($index)->updateFilterableAttributes(['merchant_id', 'branch_id']);
    $client->waitForTask($task['taskUid'], P22_TASK_TIMEOUT_MS);
}

function engineDocuments(MeilisearchClient $client, string $index): array
{
    engineSettle($client, $index);

    return $client->index($index)->getDocuments((new DocumentsQuery)->setLimit(50))->getResults();
}

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);

    Config::set('scout.driver', 'meilisearch');
    // A per-test index prefix, so a run can never touch a dev index or another run's index.
    Config::set('scout.prefix', 'servana_p22test_'.Str::lower((string) Str::ulid()).'_');
    Config::set('scout.queue', false);

    // Fail loudly rather than skipping: a skipped engine test is indistinguishable from no test.
    try {
        engineClient()->health();
    } catch (Throwable $exception) {
        $this->fail(
            'Meilisearch is not reachable at '.(string) Config::get('scout.meilisearch.host').
            ' — the Phase 22 engine tests require it ('.$exception::class.').'
        );
    }
});

afterEach(function (): void {
    $client = engineClient();
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

it('indexes a client through the Scout observer and finds it through the endpoint', function (): void {
    $scn = searchScenario();
    $index = SearchIndexName::prefixed('clients');

    // The observer already wrote the document; settings are what the search still needs.
    engineIndex(engineClient(), $index, [[
        'id' => $scn['clientA']->ulid,
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'full_name' => $scn['clientA']->full_name,
    ]]);

    $response = search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['client']])->assertOk();

    expect(searchResultUlids($response, 'client'))->toContain($scn['clientA']->ulid);
});

it('composes a real index document with exactly the declared keys', function (): void {
    $scn = searchScenario();

    // Scout composes `[getScoutKeyName() => getScoutKey()] + toSearchableArray()`. Naming the key
    // `id` (not `ulid`) is what keeps the composed document identical to the builder's allowlist.
    $scn['clientA']->searchable();

    $documents = engineDocuments(engineClient(), SearchIndexName::prefixed('clients'));

    expect($documents)->not->toBeEmpty();

    foreach ($documents as $document) {
        expect(array_keys($document))->toEqualCanonicalizing(['id', 'merchant_id', 'branch_id', 'full_name']);
    }
});

it('stores no sensitive value in the real index', function (): void {
    $scn = searchScenario();

    $scn['clientA']->searchable();

    foreach (engineDocuments(engineClient(), SearchIndexName::prefixed('clients')) as $document) {
        $encoded = (string) json_encode($document);

        expect($encoded)->not->toContain('712345678')
            ->and($encoded)->not->toContain('phone')
            ->and($encoded)->not->toContain('email')
            ->and($encoded)->not->toContain('5678');
    }
});

it('applies the tenancy filter in the ENGINE query, not only afterwards', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    $client = engineClient();
    $index = SearchIndexName::prefixed('clients');

    engineIndex($client, $index, [
        ['id' => $scn['clientA']->ulid, 'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branchA']->id, 'full_name' => 'Amina Wanjiku'],
        ['id' => $scn['clientB']->ulid, 'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branchB']->id, 'full_name' => 'Amina Wanjiku'],
        ['id' => $foreign['client']->ulid, 'merchant_id' => $foreign['merchant']->id, 'branch_id' => $foreign['branch']->id, 'full_name' => 'Amina Wanjiku'],
    ]);

    // Unfiltered, the engine itself holds all three — so the assertion below measures the filter
    // rather than an empty index.
    expect($client->index($index)->search('Amina', ['limit' => 20])->getHits())->toHaveCount(3);

    // And the engine query the resolver builds returns only the caller's own branch.
    $filtered = $client->index($index)->search('Amina', [
        'filter' => sprintf('merchant_id = %d AND branch_id IN [%d]', $scn['merchant']->id, $scn['branchA']->id),
        'limit' => 20,
        'attributesToRetrieve' => ['id'],
    ])->getHits();

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['id'])->toBe($scn['clientA']->ulid);

    $response = search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['client']])->assertOk();

    expect(searchResultUlids($response, 'client'))->toBe([$scn['clientA']->ulid]);
});

it('cannot be made to escape the engine tenancy filter through the term', function (string $term): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    engineIndex(engineClient(), SearchIndexName::prefixed('clients'), [
        ['id' => $scn['clientA']->ulid, 'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branchA']->id, 'full_name' => 'Amina Wanjiku'],
        ['id' => $foreign['client']->ulid, 'merchant_id' => $foreign['merchant']->id, 'branch_id' => $foreign['branch']->id, 'full_name' => 'Amina Wanjiku'],
    ]);

    $response = search($scn['frontOffice'], ['q' => $term, 'types' => ['client']])->assertOk();

    expect(searchResultUlids($response, 'client'))->not->toContain($foreign['client']->ulid);
})->with([
    'quote break' => 'Amina" OR merchant_id = 1 OR "',
    'bracket break' => 'Amina] OR branch_id IN [1,2,3',
    'exists' => 'Amina OR merchant_id EXISTS',
    'not' => 'Amina NOT branch_id = 0',
]);

/*
 |--------------------------------------------------------------------------
 | The engine is an ACCELERATOR, never the authority
 |--------------------------------------------------------------------------
 */

it('never resolves a POISONED engine candidate that PostgreSQL says is out of scope', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario('Zawadi Poisoned');

    $client = engineClient();
    $index = SearchIndexName::prefixed('clients');

    // A deliberately poisoned document: the FOREIGN client written with THIS merchant's tenancy
    // pair, so the engine filter would happily return it. The SQL re-resolution and the per-record
    // policy are what stop it — this is the test that proves security does not depend on the engine.
    engineIndex($client, $index, [[
        'id' => $foreign['client']->ulid,
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'full_name' => 'Zawadi Poisoned',
    ]]);

    // The engine really does hand the poisoned candidate over…
    $hits = $client->index($index)->search('Zawadi', [
        'filter' => sprintf('merchant_id = %d AND branch_id IN [%d]', $scn['merchant']->id, $scn['branchA']->id),
        'limit' => 20,
    ])->getHits();

    expect($hits)->toHaveCount(1)
        ->and($hits[0]['id'])->toBe($foreign['client']->ulid);

    // …and the endpoint still returns nothing, because the row is not this tenant's in PostgreSQL.
    $response = search($scn['frontOffice'], ['q' => 'Zawadi', 'types' => ['client']])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('never returns a stale engine document whose row no longer matches its scope', function (): void {
    $scn = searchScenario();

    engineIndex(engineClient(), SearchIndexName::prefixed('clients'), [[
        'id' => $scn['clientA']->ulid,
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'full_name' => 'Amina Wanjiku',
    ]]);

    // Move the row to the branch the actor cannot reach WITHOUT touching the index: the document is
    // now stale, and staleness must cause a MISSING result, never a leaked one.
    $scn['clientA']->forceFill(['branch_id' => $scn['branchB']->id])->saveQuietly();

    $response = search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['client']])->assertOk();

    expect(searchResultUlids($response, 'client'))->not->toContain($scn['clientA']->ulid);
});

it('removes the document from the index when the model becomes unsearchable', function (): void {
    $scn = searchScenario();
    $index = SearchIndexName::prefixed('clients');

    $scn['clientA']->searchable();
    expect(engineDocuments(engineClient(), $index))->not->toBeEmpty();

    $scn['clientA']->unsearchable();

    $ids = array_map(
        static fn (array $document): string => (string) $document['id'],
        engineDocuments(engineClient(), $index),
    );

    expect($ids)->not->toContain($scn['clientA']->ulid);
});

/*
 |--------------------------------------------------------------------------
 | The phone path never touches the engine
 |--------------------------------------------------------------------------
 */

it('never sends a phone-like term to the engine at all', function (): void {
    $scn = searchScenario();

    // A decoy whose NAME is the phone number. If the phone path ever queried the engine, this would
    // come back — the phone path is PostgreSQL blind-index only.
    /** @var Client $decoy */
    $decoy = Client::factory()->withPhone('+254700111222')->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'full_name' => '+254712345678',
    ]);

    engineIndex(engineClient(), SearchIndexName::prefixed('clients'), [[
        'id' => $decoy->ulid,
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'full_name' => '+254712345678',
    ]]);

    $response = search($scn['frontOffice'], ['q' => '+254712345678', 'types' => ['client']])->assertOk();

    // Only the blind-index match (the client who actually owns that number) — never the engine's
    // name match on the decoy.
    expect(searchResultUlids($response, 'client'))
        ->toContain($scn['clientA']->ulid)
        ->not->toContain($decoy->ulid);
});

/*
 |--------------------------------------------------------------------------
 | Degradation
 |--------------------------------------------------------------------------
 */

it('degrades to no results, never an unfiltered read, when the engine is unreachable', function (): void {
    $scn = searchScenario();

    /** @var string $liveHost */
    $liveHost = Config::get('scout.meilisearch.host');

    // Point the resolver at a dead port AFTER the scenario is built, so the failure is the query
    // rather than the fixture. Restored before the test ends so the shared cleanup can still run.
    Config::set('scout.meilisearch.host', 'http://127.0.0.1:1');

    $response = search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['client']])->assertOk();

    Config::set('scout.meilisearch.host', $liveHost);

    expect($response->json('data'))->toBe([]);
});

it('rejects the tenancy filter when index settings were never synced, and degrades safely', function (): void {
    $scn = searchScenario();
    $index = SearchIndexName::prefixed('clients');

    // Documents present, settings ABSENT: Meilisearch rejects a filter on a non-filterable
    // attribute, so the resolver returns no candidates. This is the failure mode
    // `servana:search-reindex` prevents by syncing settings first.
    $client = engineClient();
    $task = $client->index($index)->addDocuments([[
        'id' => $scn['clientA']->ulid,
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'full_name' => 'Amina Wanjiku',
    ]], 'id');
    $client->waitForTask($task['taskUid'], P22_TASK_TIMEOUT_MS);

    $response = search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['client']])->assertOk();

    // Empty — never an unfiltered read.
    expect($response->json('data'))->toBe([]);
});
