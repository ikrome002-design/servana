<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Search\Support\SearchLikeTerm;

/**
 * Normalizes and bounds the raw search term (Phase 22).
 *
 * The parser is the FIRST control on the query string, and it is deliberately narrow: it trims,
 * collapses internal whitespace, strips control characters, and enforces a length window. It does
 * NOT accept filters, operators, field selectors or any engine syntax — the term is only ever used
 * as (a) a bound `ILIKE` parameter after {@see SearchLikeTerm} escaping,
 * or (b) Meilisearch's `q` value, which is match text and never a filter expression. The filter
 * expression is built entirely from server state
 * ({@see MeilisearchCandidateResolver}).
 *
 * Length bounds match `SearchRequest`: a term shorter than 2 characters is refused because a
 * one-character prefix over a whole branch is enumeration rather than search.
 */
final class SearchQueryParser
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 120;

    /** Normalized term, or null when the input cannot be a legitimate query. */
    public function parse(string $raw): ?string
    {
        // Strip C0/C1 control characters (including NUL) before anything else: they carry no search
        // meaning and are a classic way to smuggle payloads past a naive validator.
        $stripped = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $raw) ?? '';

        // Collapse every run of whitespace to a single space so "  jane   doe " is one query.
        $collapsed = preg_replace('/\s+/u', ' ', $stripped) ?? '';

        $term = trim($collapsed);

        if ($term === '') {
            return null;
        }

        $length = mb_strlen($term);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return null;
        }

        return $term;
    }
}
