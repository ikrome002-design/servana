<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Auth\Mfa\MfaManager;
use App\Domain\Auth\Mfa\MfaSession;
use App\Domain\Auth\Mfa\MfaStatus;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MfaCodeRequest;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Real TOTP MFA flow (Plan §17, §18; Phase R3, REM-MFA-001) — replaces the
 * Phase 5 `mfa_not_enabled` placeholder.
 *
 * Thin controllers: validate → delegate to {@see MfaManager} → shape response.
 * On a successful challenge/confirmation the server-side session is marked
 * MFA-asserted and the session id is regenerated (fixation defense), then the
 * tenant context is populated so the bootstrap payload is consistent (the MFA
 * routes run outside ResolveTenantContext). Secrets/recovery codes are returned
 * once through the authenticated flow and never logged/persisted in plaintext.
 */
final class MfaController extends Controller
{
    public function __construct(
        private readonly MfaManager $manager,
        private readonly MfaStatus $status,
        private readonly MfaSession $session,
        private readonly TenantContext $context,
        private readonly TenantContextResolver $resolver,
    ) {}

    /** GET /auth/mfa — safe MFA state for the SPA. */
    public function status(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'data' => ['mfa' => $this->status->for($user, $this->sessionOrNull($request))],
        ]);
    }

    /** POST /auth/mfa/enroll — start TOTP enrollment; returns the secret + URI once. */
    public function enroll(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $enrollment = $this->manager->startEnrollment($user);

        return response()->json([
            'data' => [
                'secret' => $enrollment['secret'],
                'otpauth_uri' => $enrollment['otpauth_uri'],
                'mfa' => $this->status->for($user, $this->sessionOrNull($request)),
            ],
        ]);
    }

    /** POST /auth/mfa/confirm — confirm enrollment; returns recovery codes once. */
    public function confirm(MfaCodeRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $recoveryCodes = $this->manager->confirmEnrollment($user, $request->code());

        // Confirmation proves possession → assert the session immediately.
        $this->assertSession($request, $user);

        return response()->json([
            'data' => [
                'recovery_codes' => $recoveryCodes,
                'mfa' => $this->status->for($user, $this->sessionOrNull($request)),
            ],
        ]);
    }

    /** POST /auth/mfa/challenge — verify a TOTP code, asserting the session. */
    public function challenge(MfaCodeRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $this->manager->verifyTotp($user, $request->code());

        return $this->assertAndBootstrap($request, $user);
    }

    /** POST /auth/mfa/recovery-challenge — same contract, via a recovery code. */
    public function recoveryChallenge(MfaCodeRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $this->manager->verifyRecovery($user, $request->code());

        return $this->assertAndBootstrap($request, $user);
    }

    /**
     * POST /auth/mfa/recovery-codes — regenerate the recovery-code set
     * (step-up protected at the route). Returns the new codes once.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $recoveryCodes = $this->manager->regenerateRecoveryCodes($user);

        return response()->json([
            'data' => ['recovery_codes' => $recoveryCodes],
        ]);
    }

    /** Mark the session MFA-asserted and rotate the session id (fixation defense). */
    private function assertSession(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            $this->session->markVerified($request->session());
            $request->session()->regenerate();
        }

        // Populate tenant context so any subsequent bootstrap is consistent.
        $this->resolver->populate($this->context, $user);
    }

    /** Assert the session, then return the full bootstrap payload. */
    private function assertAndBootstrap(Request $request, User $user): JsonResponse
    {
        $this->assertSession($request, $user);

        return AuthenticatedUserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function sessionOrNull(Request $request): ?Session
    {
        return $request->hasSession() ? $request->session() : null;
    }
}
