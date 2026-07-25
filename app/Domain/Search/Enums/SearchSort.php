<?php

declare(strict_types=1);

namespace App\Domain\Search\Enums;

/**
 * Allowlisted search ordering (Plan §68 "Sort/filter fields allowlisted"; §24.2).
 *
 * A backed enum rather than a string is the injection control: no caller-supplied token ever
 * reaches an engine sort expression or a SQL `order by`. Meilisearch's `sortableAttributes` is
 * empty for every index precisely so that even a crafted option could not sort server-side.
 *
 * - `Relevance` — the engine's own ranking, preserved by re-ordering the re-resolved rows into the
 *   candidate-id order the engine returned.
 * - `Recent`    — PostgreSQL `created_at desc` on the type's own table.
 */
enum SearchSort: string
{
    case Relevance = 'relevance';
    case Recent = 'recent';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
