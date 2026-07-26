<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Enums\SearchSort;

/**
 * Orchestrates one search (Phase 22; decision D-22-01).
 *
 * The whole flow, in order:
 *
 *   1. resolve the EFFECTIVE types — the intersection of what was requested, what the catalogue
 *      offers, and what the caller's EXISTING per-type authority already permits;
 *   2. if the term is a COMPLETE phone number, take the exact blind-index path for clients and
 *      DO NOT query the search engine at all, for any type;
 *   3. otherwise, per effective type: ask the engine for candidate ULIDs under a server-built
 *      tenancy filter, re-resolve those candidates through the type's tenant-scoped Eloquent query,
 *      and re-check each surviving record against the policy its own detail route uses;
 *   4. return only mapped {@see SearchResultItem}s, which have no contact field to leak.
 *
 * A caller with no effective types gets an empty collection, NEVER a 403: a 403 would confirm which
 * document types exist and who may reach them. Zero results and no authority are therefore
 * indistinguishable from outside, which is the intended enumeration posture.
 *
 * Nothing here caches. There is no unscoped result set anywhere in the flow that could later be
 * filtered — every read is already scoped when it is issued (Plan §68).
 */
final class SearchService
{
    public function __construct(
        private readonly SearchDocumentCatalogue $catalogue,
        private readonly MeilisearchCandidateResolver $engine,
        private readonly ClientPhoneLookup $phoneLookup,
    ) {}

    /**
     * @param  list<SearchDocumentType>  $requestedTypes
     * @return array{items: list<SearchResultItem>, types: list<string>}
     */
    public function search(
        SearchContext $context,
        string $term,
        array $requestedTypes,
        SearchSort $sort,
        int $limit,
    ): array {
        $definitions = $this->catalogue->effectiveFor($context, $requestedTypes);

        if ($definitions === [] || ! $context->hasBranchScope()) {
            return ['items' => [], 'types' => []];
        }

        $effectiveTypes = array_map(
            static fn ($definition): string => $definition->type()->value,
            $definitions,
        );

        // A complete phone number is answered ONLY by the exact blind-index lookup, and the engine is
        // not consulted at all — so a phone number never reaches Meilisearch (search-security §4).
        if ($this->phoneLookup->isPhoneLike($term)) {
            $wantsClients = in_array(SearchDocumentType::Client->value, $effectiveTypes, true);

            return [
                'items' => $wantsClients ? $this->phoneLookup->lookup($context, $term, $limit) : [],
                'types' => $wantsClients ? [SearchDocumentType::Client->value] : [],
            ];
        }

        $items = [];

        foreach ($definitions as $definition) {
            $candidates = $this->engine->candidates($definition, $context, $term, $limit);

            foreach ($definition->search($context, $term, $candidates, $sort, $limit) as $item) {
                $items[] = $item;
            }
        }

        return ['items' => $items, 'types' => $effectiveTypes];
    }
}
