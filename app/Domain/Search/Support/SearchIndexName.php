<?php

declare(strict_types=1);

namespace App\Domain\Search\Support;

use Illuminate\Support\Facades\Config;

/**
 * The single place that turns a catalogue index name into a physical Meilisearch index name
 * (Phase 22).
 *
 * `config('scout.prefix')` is environment-derived (`servana_{APP_ENV}_`), so no two environments can
 * ever address the same index and a test run cannot touch the dev indexes. Both the model's
 * `searchableAs()` (which Scout uses to WRITE) and the candidate resolver (which READS) go through
 * here, so the two can never disagree about where a document lives.
 */
final class SearchIndexName
{
    public static function prefixed(string $index): string
    {
        return (string) Config::get('scout.prefix', '').$index;
    }
}
