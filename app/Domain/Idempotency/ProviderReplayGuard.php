<?php

declare(strict_types=1);

namespace App\Domain\Idempotency;

use App\Domain\Idempotency\Support\IdempotencyScopeResolver;
use Illuminate\Support\Facades\Config;

/**
 * Generic provider-callback replay/deduplication seam (Plan §24.1, §24.4;
 * Phase R4). Deterministic by `webhook:{provider}:{environment}` scope + a
 * unique correlation id, with payload-hash mismatch detection.
 *
 * This is provider-AGNOSTIC: it creates no M-Pesa tables/routes and invents no
 * signature/HMAC rules. Phase 20D (ADR-006) attaches it to provider-supported
 * correlation ids, the callback inbox uniqueness, and receipt-number constraints.
 */
final class ProviderReplayGuard
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly IdempotencyScopeResolver $scopes,
    ) {}

    /**
     * Claim a provider callback for one-time processing.
     *
     *  - First:          this correlation is new — process it.
     *  - Duplicate:      same correlation + same payload already seen / in-flight.
     *  - PayloadMismatch: same correlation reused with a different payload.
     */
    public function claim(string $provider, string $environment, string $correlationId, string $payloadHash): ProviderClaimResult
    {
        $scope = $this->scopes->forProvider($provider, $environment);
        $keyHash = hash('sha256', $correlationId);

        $lockTtl = (int) Config::get('servana.idempotency.lock_ttl_seconds', 30);
        // Provider dedupe is retained long (lives with the future financial record).
        $retention = (int) Config::get('servana.idempotency.retriable_retention_days', 30) * 86400;

        $outcome = $this->store->claim(
            $scope,
            $keyHash,
            $payloadHash,
            [
                'actor_user_id' => null,
                'merchant_id' => null,
                'branch_id' => null,
                'route_name' => substr('provider:'.$provider, 0, 191),
                'http_method' => 'POST',
                'request_content_type' => 'application/json',
            ],
            $lockTtl,
            $retention,
        );

        return match ($outcome->result) {
            ClaimResult::ConflictDifferent => ProviderClaimResult::PayloadMismatch,
            ClaimResult::Replay, ClaimResult::InProgress => ProviderClaimResult::Duplicate,
            ClaimResult::Claimed => $this->markProcessed($outcome, $retention),
        };
    }

    private function markProcessed(ClaimOutcome $outcome, int $retention): ProviderClaimResult
    {
        // Record the one-time processing effect so subsequent replays dedupe.
        if ($outcome->row !== null) {
            $this->store->complete($outcome->row, 202, [], ['accepted' => true], $retention);
        }

        return ProviderClaimResult::First;
    }
}
