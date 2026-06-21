<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditChainHasher;
use Illuminate\Console\Command;

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
 * Scheduled execution and alerting on failure are Phase 25 (Section 71); this
 * command is the verifier those build on.
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
                    $this->error(sprintf(
                        'INVALID chain %s at record %s (%s).',
                        $label,
                        $row->ulid,
                        ! $linkOk ? 'broken link' : 'hash mismatch',
                    ));
                    $failures++;
                    $chainOk = false;
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

            return self::FAILURE;
        }

        $this->info(sprintf('Audit chain verification passed: %d chain(s) valid.', $verified));

        return self::SUCCESS;
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
