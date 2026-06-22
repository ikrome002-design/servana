<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mfa;

use App\Domain\Auth\Models\MfaCredential;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Builds the SAFE MFA state payload (Plan §18; Phase R3).
 *
 * Shared by the /me bootstrap, the Magic Link verify response, and the dedicated
 * status endpoint so the SPA always reads one consistent view. Exposes only safe
 * flags — never the encrypted secret, the otpauth URI, or recovery-code hashes.
 */
final class MfaStatus
{
    public function __construct(
        private readonly MfaRequirementResolver $resolver,
        private readonly RecoveryCodeManager $recoveryCodes,
        private readonly MfaSession $session,
    ) {}

    /**
     * @return array{
     *   required: bool, enrolled: bool, confirmed: bool, verified: bool,
     *   enrollment_required: bool, challenge_required: bool, step_up_fresh: bool,
     *   step_up_fresh_until: ?string, recovery_codes_remaining: int
     * }
     */
    public function for(User $user, ?Session $session): array
    {
        $required = $this->resolver->isRequired($user);

        $credential = MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('type', MfaCredential::TYPE_TOTP)
            ->first();

        $enrolled = $credential !== null;
        $confirmed = $credential !== null && $credential->isConfirmed();

        $asserted = $session !== null && $this->session->isAsserted($session);
        $fresh = $session !== null && $this->session->isFresh($session);

        return [
            'required' => $required,
            'enrolled' => $enrolled,
            'confirmed' => $confirmed,
            'verified' => $asserted,
            'enrollment_required' => $required && ! $confirmed,
            'challenge_required' => $required && $confirmed && ! $asserted,
            'step_up_fresh' => $fresh,
            'step_up_fresh_until' => $session !== null ? $this->session->freshUntil($session) : null,
            'recovery_codes_remaining' => $confirmed ? $this->recoveryCodes->remaining($user) : 0,
        ];
    }
}
