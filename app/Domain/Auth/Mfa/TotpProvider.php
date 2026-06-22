<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mfa;

use Illuminate\Support\Facades\Config;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper over the RFC 6238 implementation (pragmarx/google2fa; Phase R3).
 *
 * We never implement the TOTP algorithm ourselves (assignment rule). The package
 * generates CSPRNG secrets, builds the otpauth provisioning URI, and verifies
 * codes in constant time (hash_equals internally). Replay prevention is layered
 * on top via {@see verify()} returning the matched time-step, which the caller
 * persists so an equal/older code can never be accepted again.
 */
final class TotpProvider
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /** A fresh CSPRNG base32 secret for a new enrollment. */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * The otpauth:// provisioning URI a QR code encodes. Returned only through
     * the authenticated enrollment-start flow; never logged/persisted.
     */
    public function provisioningUri(string $secret, string $accountEmail): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) Config::get('servana.mfa.issuer', 'Servana'),
            $accountEmail,
            $secret,
        );
    }

    /**
     * Verify a code against the secret, rejecting replays.
     *
     * Returns the matched RFC 6238 time-step (a strictly-increasing int) when
     * the code is valid AND newer than `$lastTimestep`; returns false otherwise
     * (invalid, expired, or replayed within/at the last accepted step). The
     * caller persists the returned step as the new `$lastTimestep`.
     */
    public function verify(string $secret, string $code, ?int $lastTimestep = null): int|false
    {
        $window = (int) Config::get('servana.mfa.totp_window', 1);

        // verifyKeyNewer returns the matched ABSOLUTE time-step (int) when the
        // code is newer than $oldTimestamp, else false. It only returns the int
        // when $oldTimestamp is non-null (otherwise it returns boolean true and
        // we would lose the replay state), so we pass 0 for the first-ever
        // verification — every real time-step is > 0, and the returned slice is
        // persisted as the new $lastTimestep to block reuse of an equal/older code.
        $oldTimestamp = $lastTimestep ?? 0;

        $result = $this->google2fa->verifyKeyNewer($secret, $code, $oldTimestamp, $window);

        return $result === false ? false : (int) $result;
    }
}
