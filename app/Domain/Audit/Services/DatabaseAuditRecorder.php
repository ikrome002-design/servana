<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Support\CorrelationId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Table-backed, hash-chained audit recorder (Plan §70, ADR-008).
 *
 * Chains are PER-MERCHANT, plus one platform chain for `merchant_id IS NULL`:
 * each new row links to the previous row's hash WITHIN THE SAME chain, so one
 * tenant's volume or tampering can never affect another tenant's verification.
 * Appends serialize per chain with a Postgres transaction-scoped advisory lock
 * (covers the first-row race that a row lock cannot), then take a `lockForUpdate`
 * on the chain tail. The hash itself is computed by {@see AuditChainHasher} — the
 * single algorithm shared with the verifier so the two never drift.
 *
 * Secrets are never written: callers pass only non-secret context, and the
 * sensitive read-time fields (actor_label, ip, correlation id) are excluded from
 * the hash so they can be masked at read time without breaking the chain.
 */
final class DatabaseAuditRecorder implements AuditRecorder
{
    /** Namespace key for the per-chain advisory lock (arbitrary stable int). */
    private const CHAIN_LOCK_NAMESPACE = 0x5256; // 'RV'

    public function __construct(
        private readonly CorrelationId $correlationId,
        private readonly AuditChainHasher $hasher,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        AuditEvent $event,
        ?User $actor = null,
        ?int $merchantId = null,
        ?int $branchId = null,
        ?object $subject = null,
        array $context = [],
    ): AuditLog {
        return DB::transaction(function () use ($event, $actor, $merchantId, $branchId, $subject, $context): AuditLog {
            // Serialize appends on THIS chain (per-merchant; 0 = platform chain).
            // Transaction-scoped advisory lock also guards the first-row race.
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::CHAIN_LOCK_NAMESPACE, $merchantId ?? 0]);

            $previous = AuditLog::query()
                ->when($merchantId === null,
                    fn ($q) => $q->whereNull('merchant_id'),
                    fn ($q) => $q->where('merchant_id', $merchantId),
                )
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $previousHash = $previous?->hash;

            $auditableType = $subject instanceof Model ? $subject::class : null;
            $auditableId = $subject instanceof Model ? $subject->getKey() : null;
            $auditableId = is_int($auditableId) ? $auditableId : (is_numeric($auditableId) ? (int) $auditableId : null);

            $createdAt = now();
            $fields = [
                'ulid' => (string) Str::ulid(),
                'merchant_id' => $merchantId,
                'branch_id' => $branchId,
                'actor_id' => $actor?->id,
                'action' => $event->value,
                'severity' => $event->severity()->value,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'context' => $context,
                'created_at' => $createdAt->toIso8601String(),
            ];

            $log = new AuditLog([
                ...$fields,
                'actor_label' => $actor?->email,
                'ip_address' => request()->ip(),
                'correlation_id' => $this->correlationId->get(),
                'previous_hash' => $previousHash,
            ]);
            $log->created_at = $createdAt;
            $log->hash = $this->hasher->hash($previousHash, $fields);
            $log->save();

            return $log;
        });
    }
}
