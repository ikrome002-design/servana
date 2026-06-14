<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Enums\AuthEvent;
use App\Domain\Auth\Services\LoginEligibilityService;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Auth\Support\AuthEventLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Consume a Magic Link token and resolve the authenticated user (Plan §9.1).
 *
 * Returns the User on success, or null on any failure (bad/expired/used token,
 * or eligibility re-check failure because status changed since issue). The
 * uniform null keeps the controller's 422 message non-enumerating. Login itself
 * (session guard + id regeneration) is the controller's responsibility — this
 * action holds no session state so it stays unit-testable.
 */
final class ConsumeMagicLink
{
    public function __construct(
        private readonly MagicLinkTokenService $tokens,
        private readonly LoginEligibilityService $eligibility,
        private readonly AuthEventLogger $audit,
    ) {}

    public function handle(string $rawToken): ?User
    {
        // CHECK 7 — atomic single-use consume. Null = absent/expired/used.
        $email = $this->tokens->consume($rawToken);

        if ($email === null) {
            $this->audit->record(AuthEvent::LinkFailed, null, 'invalid_or_expired_token');

            return null;
        }

        // Re-run checks 1–6 at consume time: status may have changed since issue.
        $result = $this->eligibility->check($email);

        if (! $result->eligible) {
            $this->audit->record(AuthEvent::LinkFailed, $email, $result->deniedReason);

            return null;
        }

        $user = $this->eligibility->findUser($email);

        if ($user === null) {
            $this->audit->record(AuthEvent::LinkFailed, $email, LoginEligibilityService::REASON_USER_NOT_FOUND);

            return null;
        }

        // First successful sign-in verifies the email (Magic Link possession),
        // and every sign-in stamps last_login_at (Plan §9.1, §7.1).
        DB::transaction(function () use ($user): void {
            if ($user->email_verified_at === null) {
                $user->email_verified_at = now();
            }
            $user->last_login_at = now();
            $user->save();
        });

        $this->audit->record(AuthEvent::LoginSuccess, $email, null, $user->ulid);

        return $user;
    }
}
