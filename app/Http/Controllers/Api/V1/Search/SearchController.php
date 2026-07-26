<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Search;

use App\Domain\Search\Services\SearchQueryParser;
use App\Domain\Search\Services\SearchScopeResolver;
use App\Domain\Search\Services\SearchService;
use App\Domain\Search\Support\SearchPhoneCandidate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Http\Resources\SearchResultResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/search` — the tenant-scoped, permission-aware search aggregator
 * (Plan §68; §80 Phase 22; decision D-22-01).
 *
 * THIS ROUTE GRANTS ACCESS TO NOTHING. It is authenticated, tenant-scoped, active-membership-gated
 * and rate-limited, and every result type is admitted only after the server proves the caller already
 * holds the authority governing that type's own list/detail route. There is therefore no
 * `EnsurePermission` middleware and no new permission key — the live matrix has no Phase 22 key and
 * none was invented (`docs/proof/phase-22.md` §F-3, D-22-01).
 *
 * No route-security exception was needed either: `RouteSecurityContractTest` classifies NON-GET
 * routes only, so a GET read requires no classification — exactly like the existing `clients.index`,
 * `appointments.index`, `queue.index` and `staff.index` reads, which authorize in the controller
 * through their policies.
 *
 * A caller with no searchable authority receives `200` with an empty collection, NEVER `403`: a 403
 * would confirm which document types exist and who can reach them. Zero results and no authority are
 * indistinguishable from outside.
 *
 * The controller stays thin (Plan §5.1): validate → resolve server-side scope → service → Resource.
 * It builds no query and makes no authorization decision of its own.
 */
final class SearchController extends Controller
{
    /** What `meta.query` carries instead of a phone-like term (mirrors the masking idiom). */
    public const REDACTED_QUERY = '•••';

    public function __construct(
        private readonly SearchScopeResolver $scopes,
        private readonly SearchQueryParser $parser,
        private readonly SearchService $search,
    ) {}

    public function index(SearchRequest $request): JsonResponse
    {
        /** @var string $rawQuery */
        $rawQuery = $request->validated('q');

        $term = $this->parser->parse($rawQuery);
        $limit = $request->limit();

        /** @var User $user */
        $user = $request->user();

        $context = $this->scopes->resolve($user, $request->requestedBranchUlids());

        // A term that normalizes away (control characters only, or too short once whitespace is
        // collapsed) and a request with no resolvable tenant context are both answered as "no
        // results" rather than as an error, for the same non-enumerating reason. Kept as ONE exit
        // point so the published response schema has a single shape.
        $result = $term === null || $context === null
            ? ['items' => [], 'types' => []]
            : $this->search->search(
                context: $context,
                term: $term,
                requestedTypes: $request->requestedTypes(),
                sort: $request->sort(),
                limit: $limit,
            );

        // Echoed as a plain string (never null) so the published contract is exact: the normalized
        // term when it survived normalization, otherwise the raw term the caller sent.
        //
        // A PHONE-LIKE term is redacted instead of echoed. Echoing it would put a client's phone
        // number in the response body — and therefore in anything that stores a response — which is
        // exactly what Plan §64 / ADR-010 forbid. The caller already has their own input, so nothing
        // is lost; the SPA renders the term from its own state.
        $echoedQuery = (string) ($term ?? $rawQuery);
        $echoedQuery = SearchPhoneCandidate::isPhoneLike($echoedQuery)
            ? self::REDACTED_QUERY
            : $echoedQuery;

        return SearchResultResource::collection($result['items'])
            ->additional([
                'meta' => [
                    'query' => $echoedQuery,
                    // The EFFECTIVE types — what the caller was actually authorized for, which may
                    // be narrower than what was requested and is never wider.
                    'types' => $result['types'],
                    'limit' => $limit,
                    // Always null: the aggregator returns a bounded top-N per type, and deep
                    // pagination stays with each type's own canonical list route (decision D-22-04).
                    'next_cursor' => null,
                ],
            ])
            ->response();
    }
}
