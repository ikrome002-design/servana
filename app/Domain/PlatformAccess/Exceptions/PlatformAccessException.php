<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Exceptions;

use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal platform-access lifecycle failures (COR-UI08-001 §11; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-access-membership.md.
 *
 * Renders the Phase 3 error envelope with a canonical, safe code. It never leaks a SQLSTATE, a
 * token, a session id or whether a given email address is known to the system.
 */
final class PlatformAccessException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(PlatformAccessStatus $from, PlatformAccessStatus $to): self
    {
        return new self(
            'invalid_state_transition',
            "Platform access cannot move from {$from->value} to {$to->value}.",
            422,
        );
    }

    /**
     * The lockout guard. Refusing this is the whole reason the quorum check exists: an
     * administrator who removes the last active administrator locks the platform out of itself.
     */
    public static function lastActiveAdministrator(): self
    {
        return new self(
            'platform_access.last_active_administrator',
            'This would leave no active Super Administrator. Grant access to another administrator first.',
            422,
        );
    }

    /** An actor may never reduce, escalate or otherwise act on their own access. */
    public static function selfActionForbidden(): self
    {
        return new self(
            'platform_access.self_action_forbidden',
            'An administrator cannot change their own platform access.',
            422,
        );
    }

    /** Deny-only: an override may subtract from the role defaults, never add to them. */
    public static function grantNotPermitted(): self
    {
        return new self(
            'platform_access.grant_not_permitted',
            'Platform access overrides are deny-only; an override can never add a capability.',
            422,
        );
    }

    /** A merchant-scope key can never be referenced by a platform override. */
    public static function nonPlatformPermission(string $key): self
    {
        return new self(
            'platform_access.non_platform_permission',
            "The permission {$key} is not platform-scoped and cannot be overridden here.",
            422,
        );
    }

    public static function invitationNotRedeemable(): self
    {
        return new self(
            'invalid_state_transition',
            'This invitation is no longer redeemable.',
            422,
        );
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], $this->status, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
