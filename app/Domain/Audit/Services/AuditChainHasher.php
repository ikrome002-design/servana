<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Console\Commands\VerifyAuditChain;
use App\Domain\Audit\Models\AuditLog;

/**
 * Canonical hash-chain algorithm for audit_logs (ADR-008; Plan §70).
 *
 * The SINGLE source of truth for how an audit row's hash is computed, used by
 * both {@see DatabaseAuditRecorder} (write) and {@see VerifyAuditChain}
 * (verify) so the two can never drift. The hash is a SHA-256 over the previous
 * row's hash plus this row's stable, immutable fields. Mutable read-time fields
 * (actor_label, ip_address, correlation_id) are deliberately EXCLUDED so masking
 * them at read time never invalidates the chain.
 */
final class AuditChainHasher
{
    /**
     * @param  array{ulid: string, merchant_id: int|null, branch_id: int|null, actor_id: int|null, action: string, severity: string, auditable_type: string|null, auditable_id: int|null, context: array<string, mixed>|null, created_at: string}  $fields
     */
    public function hash(?string $previousHash, array $fields): string
    {
        $material = json_encode([
            'previous' => $previousHash,
            'ulid' => $fields['ulid'],
            'merchant_id' => $fields['merchant_id'],
            'branch_id' => $fields['branch_id'],
            'actor_id' => $fields['actor_id'],
            'action' => $fields['action'],
            'severity' => $fields['severity'],
            'auditable_type' => $fields['auditable_type'],
            'auditable_id' => $fields['auditable_id'],
            'context' => $fields['context'],
            'created_at' => $fields['created_at'],
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $material);
    }

    /**
     * Recompute the stored hash of a persisted row (verifier path). Uses the
     * exact same canonical fields the recorder hashed at write time.
     */
    public function hashOf(AuditLog $log, ?string $previousHash): string
    {
        return $this->hash($previousHash, [
            'ulid' => $log->ulid,
            'merchant_id' => $log->merchant_id,
            'branch_id' => $log->branch_id,
            'actor_id' => $log->actor_id,
            'action' => $log->action,
            'severity' => $log->severity->value,
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'context' => $log->context,
            // The recorder hashes the ISO-8601 string of created_at.
            'created_at' => $log->created_at?->toIso8601String() ?? '',
        ]);
    }
}
