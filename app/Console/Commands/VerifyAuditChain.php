<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Events\AuditChainVerificationFailed;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditChainHasher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Verify the tamper-evident audit hash chains (Plan §70, ADR-008).
 *
 * Walks each PER-MERCHANT chain and the platform chain (merchant_id IS NULL) in
 * insertion order, recomputing every row's hash with the SAME {@see AuditChainHasher}
 * the recorder used. A row is invalid if its stored hash does not match the
 * recomputation (content altered) or its previous_hash does not match the prior
 * row's hash (reordered / missing / wrongly linked / forged). Chains are
 * independent: corrupting one merchant never affects another's result.
 *
 * Read-only — it never mutates an audit row. Exit 0 when all chains verify,
 * non-zero when any chain is invalid. Output is limited to safe chain ids and
 * the failing record ULID; never context, old/new values, PII, or secrets.
 *
 * Scheduled daily (routes/console.php, withoutOverlapping + onOneServer). On any
 * failure it emits ONE bounded, redacted {@see AuditChainVerificationFailed}
 * signal (severity/category/safe chain id/correlation id/count/timestamp) and a
 * matching structured Log::critical. Centralized transport, paging, dashboards,
 * runbooks, and escalation remain Phase 25 (Section 71) — a listener there
 * consumes the signal; this command only guarantees it fires exactly once.
 */
final class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify-chain
        {--merchant= : Verify only this merchant id (internal id)}
        {--platform : Verify only the platform chain (merchant_id IS NULL)}';

    protected $description = 'Verify the append-only audit_logs hash chains (per-merchant + platform).';

    public function handle(AuditChainHasher $hasher): int
    {
        $chains = $this->chainKeys();

        if ($chains === []) {
            $this->info('No audit chains to verify.');

            return self::SUCCESS;
        }

        $failures = 0;
        $verified = 0;
        $firstFailureCategory = null;
        $firstFailureChain = null;

        foreach ($chains as $merchantId) {
            $label = $merchantId === null ? 'platform' : "merchant:{$merchantId}";

            $rows = AuditLog::query()
                ->when($merchantId === null,
                    fn ($q) => $q->whereNull('merchant_id'),
                    fn ($q) => $q->where('merchant_id', $merchantId),
                )
                ->orderBy('id')
                ->get();

            $previousHash = null;
            $chainOk = true;

            foreach ($rows as $row) {
                $linkOk = $row->previous_hash === $previousHash;
                $hashOk = hash_equals($hasher->hashOf($row, $previousHash), $row->hash);

                if (! $linkOk || ! $hashOk) {
                    $category = ! $linkOk
                        ? AuditChainVerificationFailed::CATEGORY_BROKEN_LINK
                        : AuditChainVerificationFailed::CATEGORY_HASH_MISMATCH;

                    $this->error(sprintf(
                        'INVALID chain %s at record %s (%s).',
                        $label,
                        $row->ulid,
                        $category,
                    ));
                    $failures++;
                    $chainOk = false;
                    $firstFailureCategory ??= $category;
                    $firstFailureChain ??= $label;
                    break; // a broken chain cannot be trusted past the first break
                }

                $previousHash = $row->hash;
            }

            if ($chainOk) {
                $verified++;
                $this->line(sprintf('OK chain %s (%d record(s)).', $label, $rows->count()));
            }
        }

        if ($failures > 0) {
            $this->error(sprintf('Audit chain verification FAILED: %d chain(s) invalid.', $failures));
            $this->emitFailureSignal((string) $firstFailureCategory, (string) $firstFailureChain, $failures);

            return self::FAILURE;
        }

        $this->info(sprintf('Audit chain verification passed: %d chain(s) valid.', $verified));

        return self::SUCCESS;
    }

    /**
     * Emit the single bounded, redacted failure signal for this run (Plan §71):
     * a domain event a Phase-25 listener consumes + a matching structured
     * Log::critical. Carries only safe metadata — no payload, context, hashes,
     * PII, SQLSTATE, or stack trace.
     */
    private function emitFailureSignal(string $category, string $chainIdentifier, int $failedChainCount): void
    {
        $signal = new AuditChainVerificationFailed(
            severity: 'critical',
            category: $category,
            chainIdentifier: $chainIdentifier,
            correlationId: (string) Str::ulid(),
            failedChainCount: $failedChainCount,
            occurredAt: CarbonImmutable::now('UTC')->toIso8601String(),
        );

        Log::critical('audit_chain.verification_failed', $signal->toArray());

        event($signal);
    }

    /**
     * The set of chain keys to verify: the distinct merchant ids (and null for
     * the platform chain), honoring the --merchant / --platform filters.
     *
     * @return list<int|null>
     */
    private function chainKeys(): array
    {
        $merchantOption = $this->option('merchant');

        if ($merchantOption !== null) {
            return [(int) $merchantOption];
        }

        if ((bool) $this->option('platform')) {
            return [null];
        }

        /** @var list<int|null> $keys */
        $keys = AuditLog::query()
            ->select('merchant_id')
            ->distinct()
            ->orderByRaw('merchant_id NULLS FIRST')
            ->pluck('merchant_id')
            ->all();

        return $keys;
    }
}
