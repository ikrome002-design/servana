<?php

declare(strict_types=1);

namespace App\Domain\Search\Support;

use App\Domain\Messaging\Sms\Support\ServedClientSelector;

/**
 * Escapes a search term for safe use inside a SQL `LIKE`/`ILIKE` pattern (Phase 22).
 *
 * Without this a term containing `%` or `_` would widen its own pattern — `%` matching every row is
 * the difference between "search" and "dump the branch". Mirrors the escaping the Phase 21S
 * {@see ServedClientSelector} already applies, kept as one shared
 * helper so the two can never drift.
 *
 * The term is always bound as a parameter by the query builder; this escaping addresses pattern
 * metacharacters, not SQL injection (which parameter binding already prevents).
 */
final class SearchLikeTerm
{
    /** Escape `\`, `%` and `_` so the term can only ever match itself. */
    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /** A `%term%` containment pattern with metacharacters neutralized. */
    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }
}
