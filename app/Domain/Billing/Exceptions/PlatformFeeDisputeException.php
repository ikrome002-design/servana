<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Billing\Services\PlatformFeeDisputeStateMachine;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guard for the canonical platform-fee dispute workflow (Plan §13.10 [Correction 3]; Phase 20E,
 * Increment 5C). Renders the structured error envelope (Plan §11.5). Invalid state transitions are
 * raised by the {@see PlatformFeeDisputeStateMachine} as a
 * {@see BillingStateException} (`422 invalid_state_transition`); this class covers input/scope/authority.
 */
final class PlatformFeeDisputeException extends Exception
{
    public function __construct(string $message, private readonly string $errorCode, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function missingTarget(): self
    {
        return new self('A dispute must target a platform-fee ledger entry or a subscription invoice.', 'platform_fee_dispute_missing_target', 422);
    }

    public static function reasonRequired(): self
    {
        return new self('A dispute reason is required.', 'platform_fee_dispute_reason_required', 422);
    }

    public static function resolutionNoteRequired(): self
    {
        return new self('A resolution note is required.', 'platform_fee_dispute_resolution_note_required', 422);
    }

    /** A source that belongs to another tenant is treated as not-found (no cross-tenant disclosure). */
    public static function crossTenantTarget(): self
    {
        return new self('The dispute target could not be found.', 'platform_fee_dispute_target_not_found', 404);
    }

    public static function selfResolutionBlocked(): self
    {
        return new self('The dispute creator may not resolve or reject their own dispute.', 'platform_fee_dispute_self_resolution_blocked', 403);
    }

    public static function moneyChangeRequiresLedgerEntry(): self
    {
        return new self('A money-changing resolution requires a platform-fee ledger-entry target.', 'platform_fee_dispute_money_change_requires_entry', 422);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
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
