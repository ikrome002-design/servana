<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Exceptions;

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagChangeRequestStatus;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform feature-flag failures (COR-UI08-001 section 12; Phase UI-08). Renders the Phase 3 error
 * envelope with a canonical, safe code.
 */
final class PlatformFeatureFlagException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /** An unknown key is not a flag: the catalogue is code, and the API cannot mint one. */
    public static function unknownFlagKey(string $flagKey): self
    {
        return new self(
            'feature_flag_not_found',
            "No platform feature flag is defined for the key {$flagKey}.",
            404,
        );
    }

    public static function invalidTransition(PlatformFeatureFlagState $from, PlatformFeatureFlagState $to): self
    {
        return new self(
            'invalid_state_transition',
            "A feature flag cannot move from {$from->value} to {$to->value}.",
            422,
        );
    }

    public static function invalidRequestTransition(
        PlatformFeatureFlagChangeRequestStatus $from,
        PlatformFeatureFlagChangeRequestStatus $to,
    ): self {
        return new self(
            'invalid_state_transition',
            "A change request cannot move from {$from->value} to {$to->value}.",
            422,
        );
    }

    /** The maker/checker refusal. The database refuses it too. */
    public static function selfApprovalForbidden(): self
    {
        return new self(
            'feature_flag.self_approval_forbidden',
            'A feature-flag change must be approved by a different administrator than the one who requested it.',
            422,
        );
    }

    public static function requesterOnly(): self
    {
        return new self(
            'feature_flag.requester_only',
            'Only the administrator who requested this change may cancel it.',
            422,
        );
    }

    public static function pendingRequestExists(): self
    {
        return new self(
            'feature_flag.pending_request_exists',
            'This flag already has a pending change request; decide it before proposing another.',
            409,
        );
    }

    public static function environmentNotSupported(string $environment): self
    {
        return new self(
            'feature_flag.environment_not_supported',
            "This flag is not defined for the {$environment} environment.",
            422,
        );
    }

    public static function targetTypeNotSupported(string $targetType): self
    {
        return new self(
            'feature_flag.target_type_not_supported',
            "This flag does not support the {$targetType} target type.",
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
