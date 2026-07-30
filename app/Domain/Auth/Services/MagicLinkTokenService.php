<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Support\MagicLinkBinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and consumes Magic Link tokens (Plan §7.1, §9.1, §3 rule 14; ADR-019).
 *
 * Invariants (Phase 5, unchanged):
 *  - The raw token is 64 cryptographically-random bytes, base64url-encoded for
 *    URL transport. Only its SHA-256 hash is persisted.
 *  - 15-minute expiry.
 *  - Single-use, enforced by an atomic conditional UPDATE: the consume query
 *    only matches rows that are still unconsumed, uninvalidated and unexpired,
 *    and must affect exactly one row. Concurrent consumers therefore cannot both
 *    succeed (the second UPDATE matches zero rows).
 *
 * Phase UI-03 (ADR-019) adds BINDING, and removes nothing. Each token additionally carries the
 * user, the account experience, the exact intended host, the environment, a safe post-auth route
 * and an audience. {@see consume()} verifies every one of them against the ACTUAL request context
 * before it will consume, and the verification happens INSIDE the single atomic update — a
 * mismatched binding matches zero rows, so a wrong-host attempt cannot even burn the token for the
 * legitimate holder while still being unable to use it itself.
 *
 * The failure result is uniform (`null`) for every cause. Which binding failed is knowable only
 * from the audit row, never from the response (UI/UX plan §5.1, §5.4).
 */
final class MagicLinkTokenService
{
    public const EXPIRY_MINUTES = 15;

    /** The only credential audience that exists today (ADR-019 "audience"). */
    public const AUDIENCE_BROWSER_LOGIN = 'browser_login';

    /**
     * Create a token row (hash only) and return the raw token for the email link.
     * The raw token is never persisted or returned to any other caller.
     */
    public function issue(
        MagicLinkBinding $binding,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): string {
        $rawToken = $this->generateRawToken();

        MagicLoginToken::query()->create([
            'ulid' => (string) Str::ulid(),
            'email' => $this->normalizeEmail($binding->email),
            'user_id' => $binding->userId,
            'account_key' => $binding->accountKey,
            'intended_host' => $binding->host,
            'environment' => $binding->environment,
            'redirect_path' => $binding->redirectPath,
            'audience' => $binding->audience,
            'token_hash' => $this->hash($rawToken),
            'expires_at' => Carbon::now()->addMinutes(self::EXPIRY_MINUTES),
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgent !== null ? hash('sha256', $userAgent) : null,
        ]);

        return $rawToken;
    }

    /**
     * Atomically consume a token that matches the presented binding.
     *
     * Returns the consumed row on success, or null when the token does not exist / is expired /
     * already consumed / invalidated / bound to a different account, host or environment. The
     * uniform null keeps the caller's failure non-enumerating (Plan §9.1).
     *
     * The account, host and environment predicates are part of the SAME conditional update as the
     * single-use predicate. That is deliberate: checking them separately would either leak (a
     * pre-flight read that answers "this token exists but is for another host") or destroy (a
     * consume-then-validate that burns a legitimate user's token from the wrong host).
     */
    public function consume(string $rawToken, string $accountKey, string $host, string $environment): ?MagicLoginToken
    {
        $hash = $this->hash($rawToken);

        $affected = DB::table('magic_login_tokens')
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', Carbon::now())
            ->where('account_key', $accountKey)
            ->where('intended_host', $host)
            ->where('environment', $environment)
            ->where('audience', self::AUDIENCE_BROWSER_LOGIN)
            ->update([
                'consumed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        if ($affected !== 1) {
            return null;
        }

        return MagicLoginToken::query()->where('token_hash', $hash)->first();
    }

    /**
     * Classify a failed consume for the AUDIT TRAIL ONLY — never for the response.
     *
     * Reads the row by hash after a failed atomic consume, so an operator can tell a wrong-host
     * substitution attempt (an attack shape) apart from an ordinary expiry (a user waiting too
     * long). The caller must not vary its output on the result.
     */
    public function classifyFailure(string $rawToken, string $accountKey, string $host, string $environment): string
    {
        $token = MagicLoginToken::query()->where('token_hash', $this->hash($rawToken))->first();

        if ($token === null) {
            return 'invalid_or_expired_token';
        }
        if ($token->consumed_at !== null) {
            return 'token_replayed';
        }
        if ($token->invalidated_at !== null) {
            return 'token_invalidated';
        }
        if ($token->expires_at->isPast()) {
            return 'token_expired';
        }
        if ($token->environment !== $environment) {
            return 'environment_binding_mismatch';
        }
        if ($token->intended_host !== $host) {
            return 'host_binding_mismatch';
        }
        if ($token->account_key !== $accountKey) {
            return 'account_binding_mismatch';
        }

        return 'invalid_or_expired_token';
    }

    /**
     * Invalidate every still-usable Magic Link for an email (Plan §9.2, Scope
     * §3.4 suspension rule). Called by StaffLifecycleService when a user is
     * suspended/deactivated so any unconsumed link stops working immediately.
     * Returns the number of links invalidated.
     */
    public function invalidateUnconsumedForEmail(string $email): int
    {
        return DB::table('magic_login_tokens')
            ->where('email', $this->normalizeEmail($email))
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update([
                'invalidated_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }

    /** SHA-256 hex digest of the raw token (Plan §3 rule 14). */
    public function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /** 64 cryptographically secure random bytes, base64url-encoded. */
    private function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }
}
