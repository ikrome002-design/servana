<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Idempotency\Enums\IdempotencyState;
use App\Domain\Idempotency\Models\IdempotencyKey;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Config;

/**
 * Prune expired idempotency records (Plan §24.4 retention; Phase R4).
 *
 * Deletes rows past their `expires_at` in bounded batches, but NEVER an active
 * processing lock (`state = processing` AND `lock_expires_at > now`), so an
 * in-flight or crash-recoverable request is never destroyed. Standard records
 * are retained ≥72h and support-retriable financial records ≥30d via their
 * `expires_at` (set at claim/completion time).
 */
final class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune {--batch= : Max rows to delete this run}';

    protected $description = 'Prune expired idempotency_keys (never deletes an active lock).';

    public function handle(): int
    {
        $batch = (int) ($this->option('batch') ?? Config::get('servana.idempotency.prune_batch', 1000));
        $now = now();

        $ids = IdempotencyKey::query()
            ->where('expires_at', '<=', $now)
            // Exclude active processing locks.
            ->where(function (Builder $query) use ($now): void {
                $query->where('state', '!=', IdempotencyState::Processing->value)
                    ->orWhere('lock_expires_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit(max(1, $batch))
            ->pluck('id');

        $deleted = $ids->isEmpty() ? 0 : IdempotencyKey::query()->whereIn('id', $ids)->delete();

        $this->info(sprintf('Pruned %d expired idempotency record(s).', $deleted));

        return self::SUCCESS;
    }
}
