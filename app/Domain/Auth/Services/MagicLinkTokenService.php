<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Models\MagicLoginToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and consumes Magic Link tokens (Plan §7.1, §9.1, §3 rule 14).
 *
 * Invariants:
 *  - The raw token is 64 cryptographically-random bytes, base64url-encoded for
 *    URL transport. Only its SHA-256 hash is persisted.
 *  - 15-minute expiry.
 *  - Single-use, enforced by an atomic conditional UPDATE: the consume query
 *    only matches rows that are still unconsumed, uninvalidated and unexpired,
 *    and must affect exactly one row. Concurrent consumers therefore cannot both
 *    succeed (the second UPDATE matches zero rows).
 */
final class MagicLinkTokenService
{
    public const EXPIRY_MINUTES = 15;

    /**
     * Create a token row (hash only) and return the raw token for the email link.
     * The raw token is never persisted or returned to any other caller.
     */
    public function issue(string $email, ?string $ipAddress = null, ?string $userAgent = null): string
    {
        $rawToken = $this->generateRawToken();

        MagicLoginToken::query()->create([
            'ulid' => (string) Str::ulid(),
            'email' => $this->normalizeEmail($email),
            'token_hash' => $this->hash($rawToken),
            'expires_at' => Carbon::now()->addMinutes(self::EXPIRY_MINUTES),
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgent !== null ? hash('sha256', $userAgent) : null,
        ]);

        return $rawToken;
    }

    /**
     * Atomically consume a token. Returns the bound (normalized) email on success,
     * or null if the token does not exist / is expired / already consumed /
     * invalidated. The uniform null result prevents the caller from distinguishing
     * those cases (no enumeration — Plan §9.1).
     */
    public function consume(string $rawToken): ?string
    {
        $hash = $this->hash($rawToken);

        $affected = DB::table('magic_login_tokens')
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', Carbon::now())
            ->update([
                'consumed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        if ($affected !== 1) {
            return null;
        }

        $email = DB::table('magic_login_tokens')
            ->where('token_hash', $hash)
            ->value('email');

        return $email !== null ? (string) $email : null;
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
