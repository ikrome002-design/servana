<?php

declare(strict_types=1);

namespace App\Domain\Audit\Events;

use App\Console\Commands\VerifyAuditChain;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Bounded, redacted audit-chain-verification failure signal (Plan §71; Phase 19).
 *
 * The smallest application seam that the scheduled {@see VerifyAuditChain}
 * emits ONCE per failing run. It deliberately carries ONLY safe metadata — a
 * severity, a failure category, a safe chain identifier, a correlation id, the
 * number of failed chains, and a timestamp. It NEVER carries an audit payload,
 * before/after/context values, full hashes, PII, a SQLSTATE, or a stack trace.
 *
 * Centralized transport, paging, dashboards, runbooks, and production escalation
 * are Phase 25 (Section 71) — a listener there consumes this event; Phase 19 only
 * guarantees the bounded, redacted signal exists and fires exactly once.
 */
final class AuditChainVerificationFailed
{
    use Dispatchable;

    /** Allowed failure categories (never a raw error string). */
    public const CATEGORY_BROKEN_LINK = 'broken_link';

    public const CATEGORY_HASH_MISMATCH = 'hash_mismatch';

    /**
     * @param  string  $severity  fixed 'critical' (audit-chain integrity)
     * @param  string  $category  one of the CATEGORY_* constants
     * @param  string  $chainIdentifier  safe label: 'platform' or 'merchant:{internalId}'
     * @param  string  $correlationId  per-run ULID linking the signal to its verification run
     * @param  int  $failedChainCount  number of chains that failed this run
     * @param  string  $occurredAt  ISO-8601 UTC timestamp
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $category,
        public readonly string $chainIdentifier,
        public readonly string $correlationId,
        public readonly int $failedChainCount,
        public readonly string $occurredAt,
    ) {}

    /**
     * The bounded payload — the exact, complete set of fields carried by the
     * signal. Used for structured logging and asserted by the redaction test so
     * no unsafe field can ever be added silently.
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'category' => $this->category,
            'chain_identifier' => $this->chainIdentifier,
            'correlation_id' => $this->correlationId,
            'failed_chain_count' => $this->failedChainCount,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
