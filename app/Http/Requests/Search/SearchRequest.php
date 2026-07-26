<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Enums\SearchSort;
use App\Domain\Search\Services\SearchQueryParser;
use App\Domain\Search\Services\SearchScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The complete request contract for `GET /api/v1/search` (Phase 22;
 * `docs/architecture/search/search-security.md` §2).
 *
 * FIVE FIELDS, and nothing else. Every scope, permission and engine field listed in
 * {@see PROHIBITED_FIELDS} is REJECTED with 422 (in {@see after()}) rather than silently ignored.
 * Strict rejection is the deliberate choice: a 422 is positive evidence that the field has no effect,
 * whereas silence is indistinguishable from a field that quietly works. `SearchInjectionSafetyTest`
 * additionally proves that even a tolerated field could not change the executed query, because every
 * filter is built from {@see SearchScopeResolver} out of the authenticated membership.
 *
 * `branch_ulids` is the one scope-shaped field that IS accepted, and it can only ever NARROW: the
 * resolver intersects it with the membership's own reachable branches, so naming a foreign branch
 * returns fewer results, never more, and never an error that would confirm the branch exists.
 */
final class SearchRequest extends FormRequest
{
    /** Per-type result cap. Small on purpose: an aggregator is a jump-to, not a report. */
    public const DEFAULT_LIMIT = 5;

    public const MAX_LIMIT = 20;

    /**
     * Fields whose mere presence is a scope, permission or engine forgery attempt. Rejected, not
     * ignored.
     *
     * @var list<string>
     */
    public const PROHIBITED_FIELDS = [
        'merchant_id',
        'merchant_ulid',
        'branch_id',
        'branch_ids',
        'staff_profile_id',
        'staff_profile_ulid',
        'permission',
        'permissions',
        'role',
        'filter',
        'filters',
        'raw_filter',
        'index',
        'api_key',
        'include_sensitive',
        'include_phone',
        'include_email',
        'export',
        'download',
        'print',
        'copy',
    ];

    public function authorize(): bool
    {
        // Authorization is per RESULT TYPE, inside the catalogue (decision D-22-01). The route itself
        // grants nothing, so there is nothing to authorize here.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => [
                'required',
                'string',
                'min:'.SearchQueryParser::MIN_LENGTH,
                'max:'.SearchQueryParser::MAX_LENGTH,
            ],
            'types' => ['sometimes', 'array', 'max:'.count(SearchDocumentType::cases())],
            'types.*' => ['string', Rule::in(SearchDocumentType::values())],
            'branch_ulids' => ['sometimes', 'array', 'max:50'],
            'branch_ulids.*' => ['string', 'ulid'],
            'sort' => ['sometimes', 'string', Rule::in(SearchSort::values())],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    /**
     * Reject the forgery fields HERE rather than as `prohibited` rules in {@see rules()}.
     *
     * A per-field rule would make the OpenAPI generator publish all 21 of them as accepted query
     * parameters — a contract that advertises `api_key`, `include_phone` and `export` as things the
     * endpoint takes, which is worse than saying nothing. (This is the same generator trap as the
     * Phase 21S F2 finding, where a Form Request change silently changed the published schema.)
     * Validating them in an `after` callback keeps the 422 behaviour and the message identical while
     * leaving the published parameter list to the five fields that genuinely exist.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (self::PROHIBITED_FIELDS as $field) {
                    if (! $this->has($field)) {
                        continue;
                    }

                    $validator->errors()->add(
                        $field,
                        'Search scope is determined by your account and cannot be supplied in the request.',
                    );
                }
            },
        ];
    }

    /** @return list<SearchDocumentType> */
    public function requestedTypes(): array
    {
        /** @var list<string> $types */
        $types = $this->validated('types', []);

        return array_values(array_filter(array_map(
            static fn (string $value): ?SearchDocumentType => SearchDocumentType::tryFrom($value),
            $types,
        )));
    }

    /** @return list<string> */
    public function requestedBranchUlids(): array
    {
        /** @var list<string> $ulids */
        $ulids = $this->validated('branch_ulids', []);

        return $ulids;
    }

    public function sort(): SearchSort
    {
        $sort = $this->validated('sort');

        return is_string($sort) ? (SearchSort::tryFrom($sort) ?? SearchSort::Relevance) : SearchSort::Relevance;
    }

    public function limit(): int
    {
        $limit = $this->validated('limit');

        return is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
    }
}
