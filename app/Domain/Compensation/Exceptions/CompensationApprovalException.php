<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A Phase-20F approval control was not satisfied (Plan §59; Phase 20F, F8). Renders **403** —
 * the caller may hold the permission but has not met the approval control.
 *
 * Maker/checker is also a database CHECK (`approved_by <> submitted_by`), so a bypass attempt
 * fails at the DB even if an action were to skip this guard. Fresh step-up is enforced by the
 * request layer (Increment 4); the action re-asserts it so the domain can never approve without it.
 */
final class CompensationApprovalException extends Exception
{
    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** F8: the submitter can never approve their own submission. */
    public static function makerChecker(): self
    {
        return new self(
            'maker_checker_violation',
            'The person who submitted a compensation change cannot approve it.',
        );
    }

    /** F8: approval always requires a fresh step-up. */
    public static function freshStepUpRequired(): self
    {
        return new self(
            'approval_requires_fresh_step_up',
            'Approving a compensation change requires a fresh step-up verification.',
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
        ], 403, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
