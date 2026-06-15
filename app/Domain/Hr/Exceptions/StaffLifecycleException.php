<?php

declare(strict_types=1);

namespace App\Domain\Hr\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff lifecycle rule violations (Scope §3.4, Plan §27 Phase 7).
 *
 * Renders the Phase 3 error envelope with a domain-specific stable code
 * (`cannot_orphan_merchant`, `branch_assignment_required`,
 * `invalid_staff_transition`), like the other Phase 6/7 domain exceptions, so
 * the cross-cutting ErrorCode enum stays lean.
 */
final class StaffLifecycleException extends Exception
{
    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function cannotOrphanMerchant(): self
    {
        return new self(
            'cannot_orphan_merchant',
            'You cannot suspend or deactivate the only active Merchant Administrator.',
            422,
        );
    }

    public static function branchAssignmentRequired(): self
    {
        return new self(
            'branch_assignment_required',
            'A branch-scoped staff member needs an active branch assignment before activation.',
            422,
        );
    }

    public static function invalidTransition(string $message): self
    {
        return new self('invalid_staff_transition', $message, 422);
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
