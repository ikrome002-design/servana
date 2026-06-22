<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Notifications\MagicLoginLinkNotification;
use App\Domain\Auth\Services\LoginEligibilityService;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Auth\Support\AuthAuditLogger;

/**
 * Request a Magic Link (Plan §9.1).
 *
 * Side-effect-only and uniform: the controller always returns 202 regardless of
 * outcome, so this method reveals nothing to the caller (no enumeration). An
 * email is sent ONLY when all enforceable eligibility checks pass. Every denial
 * is audited with its reason; no raw token is ever logged.
 */
final class RequestMagicLink
{
    public function __construct(
        private readonly LoginEligibilityService $eligibility,
        private readonly MagicLinkTokenService $tokens,
        private readonly AuthAuditLogger $audit,
    ) {}

    public function handle(string $email, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $result = $this->eligibility->check($email);

        if (! $result->eligible) {
            // No email, no token row. Audit the denial reason (Plan §9.1).
            $this->audit->record(AuditEvent::LoginLinkDenied, $email, $result->deniedReason);

            return;
        }

        $user = $this->eligibility->findUser($email);

        if ($user === null) {
            // Defensive: eligibility passed but user vanished (race). Treat as denial.
            $this->audit->record(AuditEvent::LoginLinkDenied, $email, LoginEligibilityService::REASON_USER_NOT_FOUND);

            return;
        }

        // A newly issued link supersedes any earlier unconsumed link for this
        // identity (Plan §79 R6): only the latest link is ever usable, so a
        // previously-emailed-but-unclicked link silently stops working.
        $this->tokens->invalidateUnconsumedForEmail($email);

        $rawToken = $this->tokens->issue($email, $ipAddress, $userAgent);

        $user->notify(new MagicLoginLinkNotification($rawToken));

        $this->audit->record(AuditEvent::LoginLinkRequested, $email, null, $user->ulid);
    }
}
