<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mfa;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Exceptions\InvalidMfaCodeException;
use App\Domain\Auth\Exceptions\MfaStateException;
use App\Domain\Auth\Models\MfaCredential;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates TOTP enrollment, confirmation and challenge (Plan §18; Phase R3).
 *
 * Pure crypto/persistence/audit — it never touches the HTTP session. Marking the
 * session "MFA-asserted" (and regenerating the session id) is the controller's
 * job after a successful call here, so this class stays unit-testable.
 *
 * Security behaviour:
 *  - secrets are CSPRNG-generated and only the encrypted form is persisted;
 *  - the plaintext secret / otpauth URI are returned ONLY from startEnrollment;
 *  - recovery codes are generated only after successful confirmation;
 *  - verification is replay-protected via last_used_timestep;
 *  - failures audit `mfa.challenge_failed` and throw a uniform error.
 */
final class MfaManager
{
    public function __construct(
        private readonly TotpProvider $totp,
        private readonly RecoveryCodeManager $recoveryCodes,
        private readonly MfaAuditLogger $audit,
    ) {}

    /** The user's confirmed TOTP credential, if any. */
    public function confirmedCredential(User $user): ?MfaCredential
    {
        return MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('type', MfaCredential::TYPE_TOTP)
            ->whereNotNull('confirmed_at')
            ->first();
    }

    public function isConfirmed(User $user): bool
    {
        return $this->confirmedCredential($user) !== null;
    }

    /**
     * Begin (or safely restart) TOTP enrollment. Rotates an abandoned
     * unconfirmed credential with a fresh secret; refuses if already confirmed
     * (re-enrollment/reset is a separately-authorized future flow).
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function startEnrollment(User $user): array
    {
        if ($this->isConfirmed($user)) {
            throw MfaStateException::alreadyEnrolled();
        }

        $secret = $this->totp->generateSecret();

        // One row per (user, totp): overwrite an abandoned unconfirmed secret.
        MfaCredential::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => MfaCredential::TYPE_TOTP],
            [
                'secret_encrypted' => $secret,
                'confirmed_at' => null,
                'last_used_at' => null,
                'last_used_timestep' => null,
            ],
        );

        $this->audit->record(AuditEvent::MfaEnrollmentStarted, $user);

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->provisioningUri($secret, $user->email),
        ];
    }

    /**
     * Confirm enrollment with the first valid TOTP code. On success the
     * credential is marked confirmed and a fresh set of recovery codes is
     * generated and returned (shown once).
     *
     * @return list<string> plaintext recovery codes (display once)
     */
    public function confirmEnrollment(User $user, string $code): array
    {
        $credential = MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('type', MfaCredential::TYPE_TOTP)
            ->whereNull('confirmed_at')
            ->first();

        if ($credential === null) {
            // Either nothing started, or it is already confirmed.
            throw $this->isConfirmed($user)
                ? MfaStateException::alreadyEnrolled()
                : MfaStateException::noPendingEnrollment();
        }

        $timestep = $this->totp->verify($credential->secret_encrypted, $code, $credential->last_used_timestep);

        if ($timestep === false) {
            $this->audit->record(AuditEvent::MfaChallengeFailed, $user, ['context' => 'enrollment']);

            throw new InvalidMfaCodeException;
        }

        return DB::transaction(function () use ($credential, $user, $timestep): array {
            $credential->forceFill([
                'confirmed_at' => Carbon::now(),
                'last_used_at' => Carbon::now(),
                'last_used_timestep' => $timestep,
            ])->save();

            $codes = $this->recoveryCodes->regenerate($user);

            $this->audit->record(AuditEvent::MfaEnrollmentConfirmed, $user);

            return $codes;
        });
    }

    /** Verify a TOTP challenge for a confirmed credential. Throws on failure. */
    public function verifyTotp(User $user, string $code): void
    {
        $credential = $this->confirmedCredential($user);

        if ($credential === null) {
            throw MfaStateException::notEnrolled();
        }

        $timestep = $this->totp->verify($credential->secret_encrypted, $code, $credential->last_used_timestep);

        if ($timestep === false) {
            $this->audit->record(AuditEvent::MfaChallengeFailed, $user);

            throw new InvalidMfaCodeException;
        }

        $credential->forceFill([
            'last_used_at' => Carbon::now(),
            'last_used_timestep' => $timestep,
        ])->save();

        $this->audit->record(AuditEvent::MfaChallengeSucceeded, $user);
    }

    /** Verify (and atomically consume) a recovery code. Throws on failure. */
    public function verifyRecovery(User $user, string $code): void
    {
        if (! $this->isConfirmed($user)) {
            throw MfaStateException::notEnrolled();
        }

        if (! $this->recoveryCodes->consume($user, $code)) {
            $this->audit->record(AuditEvent::MfaChallengeFailed, $user, ['method' => 'recovery_code']);

            throw new InvalidMfaCodeException;
        }

        $this->audit->record(AuditEvent::MfaRecoveryCodeUsed, $user, [
            'remaining' => $this->recoveryCodes->remaining($user),
        ]);
    }

    /**
     * Regenerate the recovery-code set (step-up protected at the route).
     *
     * @return list<string> plaintext recovery codes (display once)
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        if (! $this->isConfirmed($user)) {
            throw MfaStateException::notEnrolled();
        }

        $codes = $this->recoveryCodes->regenerate($user);

        $this->audit->record(AuditEvent::MfaRecoveryCodesRegenerated, $user);

        return $codes;
    }
}
