<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Search\Contracts\SearchDocumentDefinition;
use App\Domain\Search\Definitions\AbstractSearchDocumentDefinition;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\Support\SearchIndexName;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;
use Throwable;

/**
 * Resolves engine-side candidate ULIDs for one catalogue type (Phase 22).
 *
 * MANDATORY ENGINE FILTER. Plan §68 forbids fetching an unscoped result and filtering it afterwards,
 * so the Meilisearch query itself always carries `merchant_id = … AND branch_id IN [ … ]`. That
 * expression is built HERE, from {@see SearchContext} only, by casting server-held values to
 * integers — there is no code path by which a request string can reach a filter expression. The
 * search term travels as Meilisearch's `q` (match text), never as filter syntax.
 *
 * The engine is an ACCELERATOR, not an authority. Its candidates are re-resolved against PostgreSQL
 * under the tenant global scopes and then re-checked per record against the type's own policy
 * ({@see AbstractSearchDocumentDefinition}). So a mis-filtered or
 * stale index can only ever cause a MISSING result — never a leaked one.
 *
 * Returning `null` means "no engine available; text-match in PostgreSQL instead". That is the state
 * under `SCOUT_DRIVER=null`, which is the testing default (decision D-22-02) — it is a deliberate
 * second search path with identical authority, not a silent production fallback: in dev, CI and
 * production the driver is `meilisearch` and this resolver is the one that runs.
 */
final class MeilisearchCandidateResolver
{
    /** Meilisearch is queried for at most this many candidates per type, whatever `limit` asks. */
    private const MAX_CANDIDATES = 100;

    private ?Client $client = null;

    private bool $clientResolved = false;

    /**
     * Candidate ULIDs in engine relevance order, or null when there is no engine to ask.
     *
     * @return list<string>|null
     */
    public function candidates(
        SearchDocumentDefinition $definition,
        SearchContext $context,
        string $term,
        int $limit,
    ): ?array {
        $indexName = $definition->indexName();

        if ($indexName === null) {
            return null; // not an indexed type (served_client) — PostgreSQL owns it
        }

        $client = $this->client();

        if ($client === null) {
            return null;
        }

        try {
            $response = $client->index(SearchIndexName::prefixed($indexName))->search($term, [
                'filter' => $this->tenancyFilter($context),
                'limit' => min($limit * 5, self::MAX_CANDIDATES),
                // The engine returns nothing but candidate ids: every displayed value is read from
                // PostgreSQL, so even a misconfigured index cannot surface a field.
                'attributesToRetrieve' => ['id'],
            ]);

            /** @var array<int, array<string, mixed>> $hits */
            $hits = $response->getHits();
        } catch (Throwable $exception) {
            // A search outage must degrade to "no results", never to an unfiltered read. The term is
            // NOT logged: a phone-like q would put a phone number in a log line (Plan §24.5).
            Log::warning('Search engine query failed; returning no candidates.', [
                'document_type' => $definition->type()->value,
                'exception' => $exception::class,
            ]);

            return [];
        }

        $ids = [];

        foreach ($hits as $hit) {
            $id = $hit['id'] ?? null;

            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * `merchant_id = X AND branch_id IN [a, b]` built from integers only.
     *
     * An empty branch set yields a filter that cannot match, which is the correct reading of "no
     * reachable branch" (the caller short-circuits before this, but the filter is safe on its own).
     */
    private function tenancyFilter(SearchContext $context): string
    {
        $branchIds = implode(', ', array_map(static fn (int $id): string => (string) $id, $context->branchIds));

        return sprintf('merchant_id = %d AND branch_id IN [%s]', $context->merchantId, $branchIds);
    }

    /** The engine client, or null when Scout is not pointed at Meilisearch (testing default). */
    private function client(): ?Client
    {
        if ($this->clientResolved) {
            return $this->client;
        }

        $this->clientResolved = true;

        if (Config::get('scout.driver') !== 'meilisearch') {
            return null;
        }

        $host = Config::get('scout.meilisearch.host');
        $key = Config::get('scout.meilisearch.key');

        if (! is_string($host) || $host === '') {
            return null;
        }

        $this->client = new Client($host, is_string($key) && $key !== '' ? $key : null);

        return $this->client;
    }
}
