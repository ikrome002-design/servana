<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mfa;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * Server-side MFA assertion state for the current session (Plan §18; Phase R3).
 *
 * The MFA assertion time lives ONLY in the server-side session — never the JWT,
 * never client storage. A Magic Link login does NOT set it (Plan §18: the Magic
 * Link is not the MFA assertion). It is set on a successful TOTP/recovery
 * challenge, survives the post-challenge session-id regeneration, and is cleared
 * on logout / session replacement.
 *
 *   - "asserted"  → an assertion exists this session (gates privileged routes).
 *   - "fresh"     → the assertion is within `step_up_window_minutes` (gates
 *                    designated sensitive actions, Plan §9.4 step 13).
 */
final class MfaSession
{
    public const KEY = 'mfa_verified_at';

    /** Record a successful MFA assertion (unix timestamp). */
    public function markVerified(Session $session): void
    {
        $session->put(self::KEY, Carbon::now()->getTimestamp());
    }

    /** Remove the assertion (logout / session replacement). */
    public function clear(Session $session): void
    {
        $session->forget(self::KEY);
    }

    public function verifiedAt(Session $session): ?int
    {
        $value = $session->get(self::KEY);

        return is_int($value) ? $value : null;
    }

    /** True when an MFA assertion exists for this session. */
    public function isAsserted(Session $session): bool
    {
        return $this->verifiedAt($session) !== null;
    }

    /** True when the assertion is within the configurable freshness window. */
    public function isFresh(Session $session): bool
    {
        $verifiedAt = $this->verifiedAt($session);

        if ($verifiedAt === null) {
            return false;
        }

        $windowSeconds = $this->windowMinutes() * 60;

        return (Carbon::now()->getTimestamp() - $verifiedAt) <= $windowSeconds;
    }

    /** ISO-8601 instant until which the current assertion stays fresh, or null. */
    public function freshUntil(Session $session): ?string
    {
        $verifiedAt = $this->verifiedAt($session);

        if ($verifiedAt === null) {
            return null;
        }

        return Carbon::createFromTimestamp($verifiedAt)
            ->addMinutes($this->windowMinutes())
            ->toIso8601String();
    }

    private function windowMinutes(): int
    {
        return max(1, (int) Config::get('servana.mfa.step_up_window_minutes', 5));
    }
}
