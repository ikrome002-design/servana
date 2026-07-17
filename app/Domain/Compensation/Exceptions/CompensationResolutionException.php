<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Effective-configuration resolution failed closed (Plan §59; Phase 20F, F5). Resolution NEVER
 * falls back to a silent default and NEVER picks arbitrarily between conflicting rows.
 *
 *  - `effective_plan_conflict` — more than one active plan resolved for the same
 *    (staff profile, branch, date). The DB EXCLUDE makes this unreachable; if it is ever reached
 *    the invariant is broken, so the resolver raises rather than guessing which plan is real.
 *  - `effective_commission_rule_missing` — a `commission_only`/`salary_plus_commission` plan
 *    resolved no active commission rule. `salary_only` resolves null legitimately and never
 *    reaches here.
 */
final class CompensationResolutionException extends Exception
{
    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function effectivePlanConflict(): self
    {
        return new self(
            'effective_plan_conflict',
            'More than one effective compensation plan was found for this personnel and date.',
        );
    }

    public static function effectiveCommissionRuleMissing(): self
    {
        return new self(
            'effective_commission_rule_missing',
            'This compensation model requires a commission rule, but no effective rule was found.',
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
        ], 409, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
