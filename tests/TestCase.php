<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Auth\Mfa\MfaManager;
use App\Domain\Auth\Mfa\MfaRequirementResolver;
use App\Domain\Auth\Mfa\MfaSession;
use App\Domain\Auth\Models\MfaCredential;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Whether `actingAs(..., 'sanctum')` should default to an MFA-asserted
     * session. Phase R3 made MFA mandatory for Super Administrator / Merchant
     * Administrator / Finance, so pre-R3 role/permission tests — which assume a
     * fully-authenticated session — would otherwise be blocked by
     * EnsurePrivilegedMfa. Tests that specifically exercise MFA enrollment /
     * challenge / step-up state call {@see withoutMfaSession()} to take full
     * control of the credential and session-assertion state.
     */
    protected bool $defaultMfaSession = true;

    /**
     * For a mandatory-MFA user, transparently provision a confirmed credential
     * and an MFA-asserted stateful session so existing authorization tests keep
     * exercising the route they target rather than the new MFA gate.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        if ($this->defaultMfaSession && $guard === 'sanctum' && $user instanceof User) {
            if (app(MfaRequirementResolver::class)->isRequired($user)
                && app(MfaManager::class)->confirmedCredential($user) === null) {
                MfaCredential::factory()->confirmed()->create(['user_id' => $user->getKey()]);
            }

            // Origin from a stateful domain makes Sanctum apply the session
            // middleware so the MFA assertion below is readable on /api/v1.
            $this->withHeader('Origin', 'http://localhost');
            $this->withSession([MfaSession::KEY => now()->getTimestamp()]);
        }

        return $this;
    }

    /** Opt out of the default MFA-asserted session (MFA-state tests). */
    protected function withoutMfaSession(): static
    {
        $this->defaultMfaSession = false;

        return $this;
    }

    /**
     * A stateful request builder for MFA-state tests: opts out of the automatic
     * MFA session, sends a stateful Origin so the SPA session middleware applies,
     * and (optionally) seeds an MFA assertion at `$verifiedAt` (unix seconds).
     * Call BEFORE actingAs().
     */
    public function statefulMfa(?int $verifiedAt = null): static
    {
        $this->defaultMfaSession = false;
        $this->withHeader('Origin', 'http://localhost');

        if ($verifiedAt !== null) {
            $this->withSession([MfaSession::KEY => $verifiedAt]);
        }

        return $this;
    }
}
