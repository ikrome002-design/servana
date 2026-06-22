<?php

declare(strict_types=1);

namespace App\Domain\Idempotency;

use App\Domain\Idempotency\Enums\IdempotencyState;
use App\Domain\Idempotency\Models\IdempotencyKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The durable correctness boundary for idempotency (Plan §24.4; Phase R4).
 *
 * Concurrency is enforced by PostgreSQL — the `UNIQUE (idempotency_scope,
 * key_hash)` constraint and `SELECT ... FOR UPDATE` — never by process memory:
 *
 *  - First claim: `INSERT ... ON CONFLICT DO NOTHING` (via `insertOrIgnore`). The
 *    single inserter wins; everyone else falls through to the locked-resolution
 *    path. This avoids the aborted-transaction problem of catching a unique
 *    violation mid-transaction.
 *  - Existing row: resolved under a row lock — completed → replay; different
 *    request → conflict; processing + active lock → in-progress; processing +
 *    expired lock OR failed → reclaim (only one worker wins the FOR UPDATE).
 */
final class IdempotencyStore
{
    /**
     * @param  array{actor_user_id: int|null, merchant_id: int|null, branch_id: int|null, route_name: string, http_method: string, request_content_type: string|null}  $meta
     */
    public function claim(
        string $scope,
        string $keyHash,
        string $requestHash,
        array $meta,
        int $lockTtlSeconds,
        int $retentionSeconds,
    ): ClaimOutcome {
        // Phase 1 — atomic first-claim. ON CONFLICT DO NOTHING never errors, so a
        // losing racer simply inserts zero rows and proceeds to phase 2.
        $claimed = $this->tryInsert($scope, $keyHash, $requestHash, $meta, $lockTtlSeconds, $retentionSeconds);
        if ($claimed !== null) {
            return ClaimOutcome::claimed($claimed);
        }

        // Phase 2 — a row exists; resolve it under a row lock.
        return DB::transaction(function () use ($scope, $keyHash, $requestHash, $meta, $lockTtlSeconds, $retentionSeconds): ClaimOutcome {
            $row = IdempotencyKey::query()
                ->where('idempotency_scope', $scope)
                ->where('key_hash', $keyHash)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                // Rare: pruned between phase 1 and 2 — re-insert inside the lock window.
                $reinserted = $this->tryInsert($scope, $keyHash, $requestHash, $meta, $lockTtlSeconds, $retentionSeconds);

                return $reinserted !== null
                    ? ClaimOutcome::claimed($reinserted)
                    : ClaimOutcome::inProgress($lockTtlSeconds);
            }

            if (! hash_equals($row->request_hash, $requestHash)) {
                return ClaimOutcome::conflict();
            }

            return match ($row->state) {
                IdempotencyState::Completed => ClaimOutcome::replay($row),
                IdempotencyState::Failed => $this->reclaim($row, $lockTtlSeconds),
                IdempotencyState::Processing => $row->lockIsActive()
                    ? ClaimOutcome::inProgress($this->retryAfter($row))
                    : $this->reclaim($row, $lockTtlSeconds),
            };
        });
    }

    /**
     * Mark a claimed row completed with a replay-safe (encrypted) response.
     *
     * @param  array<string, string>  $headers
     * @param  array<mixed>  $body
     */
    public function complete(
        IdempotencyKey $row,
        int $status,
        array $headers,
        array $body,
        int $retentionSeconds,
    ): void {
        $now = Carbon::now();
        $row->forceFill([
            'state' => IdempotencyState::Completed,
            'response_status' => $status,
            'response_headers' => $headers,
            'response_body_encrypted' => $body,
            'completed_at' => $now,
            'lock_expires_at' => $now, // release the lock
            'expires_at' => $now->copy()->addSeconds($retentionSeconds),
        ])->save();
    }

    /** Mark a claimed row failed with a redacted code (server failure; retryable). */
    public function fail(IdempotencyKey $row, string $errorCode): void
    {
        $now = Carbon::now();
        $row->forceFill([
            'state' => IdempotencyState::Failed,
            'last_error_code' => $errorCode,
            'failed_at' => $now,
            'lock_expires_at' => $now, // release the lock so a retry can reclaim
        ])->save();
    }

    /**
     * @param  array{actor_user_id: int|null, merchant_id: int|null, branch_id: int|null, route_name: string, http_method: string, request_content_type: string|null}  $meta
     */
    private function tryInsert(
        string $scope,
        string $keyHash,
        string $requestHash,
        array $meta,
        int $lockTtlSeconds,
        int $retentionSeconds,
    ): ?IdempotencyKey {
        $now = Carbon::now();

        $affected = DB::table('idempotency_keys')->insertOrIgnore([
            'ulid' => (string) Str::ulid(),
            'idempotency_scope' => $scope,
            'key_hash' => $keyHash,
            'actor_user_id' => $meta['actor_user_id'],
            'merchant_id' => $meta['merchant_id'],
            'branch_id' => $meta['branch_id'],
            'route_name' => $meta['route_name'],
            'http_method' => $meta['http_method'],
            'request_content_type' => $meta['request_content_type'],
            'request_hash' => $requestHash,
            'state' => IdempotencyState::Processing->value,
            'locked_at' => $now,
            'lock_expires_at' => $now->copy()->addSeconds($lockTtlSeconds),
            'expires_at' => $now->copy()->addSeconds($retentionSeconds),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($affected !== 1) {
            return null;
        }

        return IdempotencyKey::query()
            ->where('idempotency_scope', $scope)
            ->where('key_hash', $keyHash)
            ->first();
    }

    /** Take a fresh lock on a reclaimable row (expired processing, or failed). */
    private function reclaim(IdempotencyKey $row, int $lockTtlSeconds): ClaimOutcome
    {
        $now = Carbon::now();
        $row->forceFill([
            'state' => IdempotencyState::Processing,
            'locked_at' => $now,
            'lock_expires_at' => $now->copy()->addSeconds($lockTtlSeconds),
            'last_error_code' => null,
        ])->save();

        return ClaimOutcome::claimed($row);
    }

    private function retryAfter(IdempotencyKey $row): int
    {
        return max(1, (int) ceil(Carbon::now()->diffInSeconds($row->lock_expires_at, false)));
    }
}
