<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fail-closed guard for the percentage platform-fee engine (Plan §51, §52; Phase 20E). When a
 * percentage-bearing billing mode is active but a required input is missing or inconsistent, the engine
 * raises this instead of silently defaulting a merchant into a liability-changing calculation. Renders a
 * structured 422 envelope (Plan §11.5).
 */
final class PlatformFeeException extends Exception
{
    public function __construct(string $message, private readonly string $errorCode, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function missingConfiguration(string $mode, string $currency, string $onDate): self
    {
        return new self(
            "No active percentage platform-fee configuration for mode '{$mode}', currency '{$currency}' on {$onDate}.",
            'platform_fee_configuration_missing',
        );
    }

    public static function missingTier(): self
    {
        return new self(
            'A percentage billing mode is active but no service-fee tier could be resolved for the merchant.',
            'platform_fee_tier_missing',
        );
    }

    public static function sharedSplitMissing(): self
    {
        return new self(
            'The shared tier requires a configured shared-split basis-points value.',
            'platform_fee_shared_split_missing',
        );
    }

    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self(
            "Platform-fee currency mismatch: expected '{$expected}', got '{$actual}'.",
            'platform_fee_currency_mismatch',
        );
    }

    /**
     * Gate 4.2 (product-owner decision): validated_paid_amount is future-dependent and may not drive a
     * finalization-time client-shifted line, so it is valid only with a customer_centric resolved tier.
     * Enforced here at the resolved-effective-tier level (a merchant override may differ from the config
     * default that the DB CHECK constrains).
     */
    public static function validatedPaidRequiresCustomerCentric(string $resolvedTier): self
    {
        return new self(
            "fee_basis_type 'validated_paid_amount' requires a customer_centric resolved tier; got '{$resolvedTier}'.",
            'platform_fee_validated_paid_tier_incompatible',
        );
    }

    /**
     * A reversal or negative adjustment may not exceed the remaining reversible balance of the original
     * earned entry (Plan §953; Phase 20E, Increment 5B). 409 — the request conflicts with the current
     * ledger state (the balance has already been consumed by prior corrections).
     */
    public static function overReversal(int $requestedMinor, int $remainingMinor): self
    {
        return new self(
            "Platform-fee correction of {$requestedMinor} exceeds the remaining reversible balance of {$remainingMinor}.",
            'platform_fee_over_reversal',
            409,
        );
    }

    /** The signed correction amount is incompatible with its adjustment type (Phase 20E, Increment 5B). */
    public static function invalidCorrectionSign(string $type): self
    {
        return new self(
            "The signed amount is incompatible with adjustment type '{$type}'.",
            'platform_fee_invalid_correction_sign',
        );
    }

    /**
     * Approved monetary terms are immutable — only a `draft` configuration may be edited in place; any
     * other state must be superseded (a new version), never edited (Plan §52; Phase 20E, Increment 6).
     */
    public static function notEditable(string $status): self
    {
        return new self(
            "A platform-fee configuration in state '{$status}' is immutable; supersede it instead of editing.",
            'platform_fee_configuration_not_editable',
        );
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
