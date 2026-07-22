<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase-20F compensation-configuration validation failure (Plan §59; Scope §12.2-§12.9). The
 * database CHECKs are the authoritative guards (F1 model shape, F4 value shape, backdating
 * fail-closed); these action-level checks fail earlier with a friendly, typed 422 rather than
 * letting a constraint violation surface. `code` distinguishes the failure surface.
 *
 * Messages are generic and safe: no SQLSTATE, no constraint name, no internal id, no stack trace.
 */
final class CompensationValidationException extends Exception
{
    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** F1: the compensation model's salary/commission-rule shape is incoherent. */
    public static function compensationModelShape(string $detail): self
    {
        return new self('compensation_model_shape_invalid', $detail);
    }

    /** F4: percentage vs fixed commission value shape is incoherent. */
    public static function commissionRuleShape(string $detail): self
    {
        return new self('commission_rule_shape_invalid', $detail);
    }

    /** §9.1: the selected-services membership set for a commission rule is invalid. */
    public static function selectedServices(string $detail): self
    {
        return new self('selected_services_invalid', $detail);
    }

    /** F8: a backdated change always requires an explicit reason. */
    public static function backdatedApprovalRequiresReason(): self
    {
        return new self(
            'backdated_approval_requires_reason',
            'A backdated compensation change requires an explicit reason.',
        );
    }

    /** F8: a backdated change always requires an impact preview before approval. */
    public static function backdatedApprovalRequiresImpactPreview(): self
    {
        return new self(
            'backdated_approval_requires_impact_preview',
            'A backdated compensation change requires an impact preview before approval.',
        );
    }

    /** H12: an earnings-query response must resolve or reject — no other transition is permitted. */
    public static function earningsQueryDecision(): self
    {
        return new self(
            'earnings_query_invalid_decision',
            'An earnings query response must resolve or reject the query.',
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
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
