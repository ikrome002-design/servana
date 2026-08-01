<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Services\LoginEligibilityService;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Auth\Support\AuthAuditLogger;
use App\Domain\Auth\Support\MagicLinkConsumeResult;
use App\Domain\Sessions\Services\AccountContextResolver;
use App\Http\Hosts\AccountHostUrlGenerator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Consume a host-bound Magic Link (Plan §9.1; ADR-019; UI/UX plan §5.1).
 *
 * Returns a {@see MagicLinkConsumeResult} on success, or null on ANY failure — bad, expired,
 * replayed, invalidated, wrong-host, wrong-account or wrong-environment token, or an eligibility
 * re-check that now fails because status changed since issue. The uniform null keeps the
 * controller's 422 non-enumerating: a caller cannot tell which binding was wrong, and neither can
 * an attacker.
 *
 * Login itself (session guard, id regeneration, session-family binding) is the controller's
 * responsibility — this action holds no session state so it stays unit-testable.
 *
 * A consumed Magic Link asserts NO MFA. Mandatory-role MFA is enforced afterwards by
 * EnsurePrivilegedMfa, in the existing R3 order (Plan §18, §9.4 step 2).
 */
final class ConsumeMagicLink
{
    public function __construct(
        private readonly MagicLinkTokenService $tokens,
        private readonly LoginEligibilityService $eligibility,
        private readonly AccountContextResolver $contexts,
        private readonly AccountHostUrlGenerator $urls,
        private readonly AuthAuditLogger $audit,
    ) {}

    public function handle(string $rawToken, string $accountKey, string $host, string $environment): ?MagicLinkConsumeResult
    {
        // CHECK 7 + the ADR-019 bindings — one atomic single-use consume. Null = absent, expired,
        // used, invalidated, or bound to a different account/host/environment.
        $token = $this->tokens->consume($rawToken, $accountKey, $host, $environment);

        if ($token === null) {
            $reason = $this->tokens->classifyFailure($rawToken, $accountKey, $host, $environment);

            // The RESPONSE is uniform; only the audit event varies, so an operator can still tell
            // a substitution attempt from an ordinary expiry.
            $this->audit->record($this->eventFor($reason), null, $reason);

            return null;
        }

        // Re-run checks 1–6 at consume time: status may have changed since issue.
        $email = (string) $token->email;
        $result = $this->eligibility->check($email);

        if (! $result->eligible) {
            $this->audit->record(AuditEvent::LoginLinkFailed, $email, $result->deniedReason);

            return null;
        }

        $user = $this->eligibility->findUser($email);

        if ($user === null) {
            $this->audit->record(AuditEvent::LoginLinkFailed, $email, LoginEligibilityService::REASON_USER_NOT_FOUND);

            return null;
        }

        // The bound user must still BE this user. A rebound email (address reassigned between
        // issue and consume) must not hand the new owner the previous owner's link.
        if ($token->user_id !== null && $token->user_id !== $user->id) {
            $this->audit->record(AuditEvent::LoginLinkFailed, $email, 'user_binding_mismatch', $user->ulid);

            return null;
        }

        // And the account context must STILL be enterable — a role removed between issue and
        // consume must not produce a session for the account it used to grant.
        $context = $this->contexts->findForUser($user, $accountKey, $environment);

        if ($context === null) {
            $this->audit->record(AuditEvent::LoginLinkFailed, $email, RequestMagicLink::REASON_ACCOUNT_NOT_AVAILABLE, $user->ulid);

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

        $this->audit->record(AuditEvent::LoginSuccess, $email, $accountKey, $user->ulid);

        return new MagicLinkConsumeResult(
            user: $user,
            context: $context,
            // Re-validated at consume, not trusted from storage: a value that was safe at issue
            // must still be safe now, and a stored path is still a path the application will act on.
            redirectPath: $this->urls->safeRelativePath($token->redirect_path),
        );
    }

    /** Route the failure to the right typed event. The RESPONSE never varies (UI/UX plan §5.4). */
    private function eventFor(string $reason): AuditEvent
    {
        return match ($reason) {
            'host_binding_mismatch', 'account_binding_mismatch' => AuditEvent::MagicLinkHostBindingRejected,
            'environment_binding_mismatch' => AuditEvent::MagicLinkEnvironmentBindingRejected,
            default => AuditEvent::LoginLinkFailed,
        };
    }
}
