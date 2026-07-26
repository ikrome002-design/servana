<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Support\ClientContactIndex;
use App\Domain\Clients\Support\PhoneNumberNormalizer;
use App\Domain\Search\Definitions\ClientSearchDefinition;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Support\SearchPhoneCandidate;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The ONLY phone-search path in Servana search (Phase 22;
 * `docs/architecture/search/search-security.md` §4).
 *
 * Shape, exactly:
 *   1. the browser submits `q` and nothing else;
 *   2. {@see SearchPhoneCandidate} decides whether `q` is a COMPLETE phone number — a partial
 *      fragment is refused, because digit-by-digit confirmation is an enumeration oracle;
 *   3. a phone-like `q` NEVER reaches Meilisearch, for any type: the engine is not queried at all on
 *      this path, and no phone digit is indexed anywhere, so it could not match there either;
 *   4. the term is normalized by the existing {@see PhoneNumberNormalizer}
 *      and hashed by the existing keyed HMAC {@see ClientContactIndex};
 *   5. an EXACT `where phone_index = :digest` lookup runs inside the tenant/branch scope;
 *   6. authority is `client.view` AND `front_office.search` — the same pair the live
 *      `GET /api/v1/clients?q=` requires — plus the per-record `ClientPolicy::view` re-check;
 *   7. the result carries the client's NAME only. No phone in any form, masked or otherwise.
 *
 * There is no decrypted-phone scan anywhere in the codebase and this class adds none. If the blind
 * index cannot be computed the lookup FAILS CLOSED to an empty result rather than falling back to any
 * weaker mechanism.
 */
final class ClientPhoneLookup
{
    public function __construct(private readonly ClientSearchDefinition $clients) {}

    public function isPhoneLike(string $term): bool
    {
        return SearchPhoneCandidate::isPhoneLike($term);
    }

    /**
     * Exact, branch-scoped, masked-free lookup. Returns an empty list for an unauthorized caller,
     * an unknown number, or an unusable blind index — all three are indistinguishable to the caller,
     * which is what stops the endpoint from confirming whether a guessed number exists.
     *
     * @return list<SearchResultItem>
     */
    public function lookup(SearchContext $context, string $term, int $limit): array
    {
        if (! $this->clients->canSearch($context) || ! $context->hasBranchScope()) {
            return [];
        }

        try {
            $digest = ClientContactIndex::for($term);
        } catch (Throwable) {
            // No usable digits, or no index key in a non-production environment: fail closed.
            return [];
        }

        $clients = Client::query()
            ->where('clients.merchant_id', $context->merchantId)
            ->whereIn('clients.branch_id', $context->branchIds)
            ->where('clients.phone_index', $digest)
            ->with('branch')
            ->limit($limit)
            ->get();

        $items = [];

        foreach ($clients as $client) {
            if (! Gate::forUser($context->user)->allows('view', $client)) {
                continue;
            }

            $items[] = $this->clients->resultFor($client);
        }

        return $items;
    }
}
