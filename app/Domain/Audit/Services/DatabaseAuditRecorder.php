<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Support\CorrelationId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Table-backed, hash-chained audit recorder (Plan §7.5, §22.2).
 *
 * Each record links to the previous row's hash so the chain is tamper-evident.
 * The append runs inside a transaction with a lock on the latest row so
 * concurrent writers cannot fork the chain. Phase 19 adds the chain verifier,
 * masking, and the full §5.18 event catalogue; the write path is stable now so
 * the financial phases never retrofit auditing.
 */
final class DatabaseAuditRecorder implements AuditRecorder
{
    public function __construct(private readonly CorrelationId $correlationId) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        AuditSeverity $severity,
        ?User $actor = null,
        ?int $merchantId = null,
        ?object $subject = null,
        array $context = [],
    ): AuditLog {
        return DB::transaction(function () use ($action, $severity, $actor, $merchantId, $subject, $context): AuditLog {
            $previous = AuditLog::query()->lockForUpdate()->orderByDesc('id')->first();
            $previousHash = $previous?->hash;

            $ulid = (string) Str::ulid();
            $createdAt = now();

            $auditableType = $subject instanceof Model ? $subject::class : null;
            $auditableId = $subject instanceof Model ? $subject->getKey() : null;
            $auditableId = is_int($auditableId) ? $auditableId : (is_numeric($auditableId) ? (int) $auditableId : null);

            $payload = [
                'ulid' => $ulid,
                'merchant_id' => $merchantId,
                'actor_id' => $actor?->id,
                'actor_label' => $actor?->email,
                'action' => $action,
                'severity' => $severity->value,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'context' => $context,
                'ip_address' => request()->ip(),
                'correlation_id' => $this->correlationId->get(),
                'previous_hash' => $previousHash,
                'created_at' => $createdAt->toIso8601String(),
            ];

            $log = new AuditLog($payload);
            $log->created_at = $createdAt;
            $log->hash = $this->chainHash($previousHash, $payload);
            $log->save();

            return $log;
        });
    }

    /**
     * Deterministic SHA-256 over the previous hash + the record's stable fields.
     *
     * @param  array<string, mixed>  $payload
     */
    private function chainHash(?string $previousHash, array $payload): string
    {
        $material = json_encode([
            'previous' => $previousHash,
            'ulid' => $payload['ulid'],
            'merchant_id' => $payload['merchant_id'],
            'actor_id' => $payload['actor_id'],
            'action' => $payload['action'],
            'severity' => $payload['severity'],
            'auditable_type' => $payload['auditable_type'],
            'auditable_id' => $payload['auditable_id'],
            'context' => $payload['context'],
            'created_at' => $payload['created_at'],
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $material);
    }
}
