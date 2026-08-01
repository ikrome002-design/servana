<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Notifications\MagicLoginLinkNotification;
use App\Domain\Auth\Services\LoginEligibilityService;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Auth\Support\AuthAuditLogger;
use App\Domain\Auth\Support\MagicLinkBinding;
use App\Domain\Sessions\Services\AccountContextResolver;
use App\Http\Hosts\AccountHostUrlGenerator;

/**
 * Request a Magic Link bound to one account host (Plan §9.1; ADR-019; UI/UX plan §5.1).
 *
 * Side-effect-only and uniform: the controller always returns 202 regardless of
 * outcome, so this method reveals nothing to the caller (no enumeration). An
 * email is sent ONLY when all enforceable eligibility checks pass. Every denial
 * is audited with its reason; no raw token is ever logged.
 *
 * Phase UI-03 adds one denial reason to the existing seven checks: the user must actually hold the
 * ACCOUNT CONTEXT the request arrived for. A Finance-host request from a Personnel-only user sends
 * nothing — and looks, from outside, exactly like a request for an address that does not exist.
 * That is the point: revealing "this email exists, but not as Finance" would leak both the account
 * and the role.
 */
final class RequestMagicLink
{
    public function __construct(
        private readonly LoginEligibilityService $eligibility,
        private readonly MagicLinkTokenService $tokens,
        private readonly AccountContextResolver $contexts,
        private readonly AccountHostUrlGenerator $urls,
        private readonly AuthAuditLogger $audit,
    ) {}

    public const REASON_ACCOUNT_NOT_AVAILABLE = 'account_context_not_available';

    public function handle(
        string $email,
        string $accountKey,
        string $host,
        string $environment,
        ?string $redirectPath = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
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

        // The account experience the request arrived on must be one this user can actually enter.
        // Derived from the database, never from the host: the host only says WHICH account was
        // asked for (ADR-017).
        $context = $this->contexts->findForUser($user, $accountKey, $environment);

        if ($context === null) {
            $this->audit->record(AuditEvent::LoginLinkDenied, $email, self::REASON_ACCOUNT_NOT_AVAILABLE, $user->ulid);

            return;
        }

        // A newly issued link supersedes any earlier unconsumed link for this
        // identity (Plan §79 R6): only the latest link is ever usable, so a
        // previously-emailed-but-unclicked link silently stops working.
        $this->tokens->invalidateUnconsumedForEmail($email);

        // Validated here, at the boundary. An unsafe value is DROPPED rather than bound, so the
        // link still works and lands on the account dashboard — binding an unsafe path would make
        // the credential itself unusable and turn a bad query string into a denial of service.
        $safeRedirect = $this->urls->safeRelativePath($redirectPath);

        $rawToken = $this->tokens->issue(
            new MagicLinkBinding(
                email: $email,
                userId: $user->id,
                accountKey: $accountKey,
                host: $host,
                environment: $environment,
                redirectPath: $safeRedirect,
            ),
            $ipAddress,
            $userAgent,
        );

        // The verify URL is built from the REGISTRY, never from the request's Host header — that is
        // what stops a poisoned host being reflected into the email (the password-reset-poisoning
        // shape). AccountHostUrlGenerator is the only thing allowed to name a host here.
        $verifyUrl = $this->urls->to($accountKey, '/auth/verify', $environment);

        $user->notify(new MagicLoginLinkNotification($rawToken, $verifyUrl));

        $this->audit->record(AuditEvent::LoginLinkRequested, $email, $accountKey, $user->ulid);
    }
}
