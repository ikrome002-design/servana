<?php

declare(strict_types=1);

namespace App\Domain\Search\Support;

use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;
use Throwable;

/**
 * Waits for a Meilisearch index to finish its queued work (Phase 22).
 *
 * Meilisearch indexing is ASYNCHRONOUS: `addDocuments` returns a task id and the documents become
 * queryable some time later. Two Servana commands would otherwise be misleading because of it:
 *
 *   - `servana:search-reindex` would report success while the documents were still queued, so an
 *     operator who ran a backfill and immediately searched would get nothing and reasonably conclude
 *     the backfill had failed;
 *   - `servana:search-verify-counts` would compare a settled PostgreSQL count against an unsettled
 *     index count and report drift that does not exist.
 *
 * Both therefore settle first. The wait is BOUNDED: a genuinely stuck queue must surface as drift or
 * as a slow command, never as a command that hangs forever.
 */
final class SearchEngineTasks
{
    /** Default budget. Generous, because a large backfill legitimately takes a while to apply. */
    public const DEFAULT_TIMEOUT_SECONDS = 60.0;

    /**
     * Block until the index has no enqueued or processing task, or the budget expires.
     *
     * Returns true when the index settled, false when the budget expired or the engine could not be
     * asked — the caller decides what that means (the drift check reports it; the backfill notes it).
     */
    public static function settle(
        Client $client,
        string $index,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ): bool {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            try {
                $pending = $client->getTasks(
                    (new TasksQuery)
                        ->setIndexUids([$index])
                        ->setStatuses(['enqueued', 'processing'])
                        ->setLimit(1),
                )->getResults();
            } catch (Throwable) {
                return false;
            }

            if ($pending === []) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }
}
