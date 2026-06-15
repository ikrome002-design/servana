<?php

declare(strict_types=1);

namespace App\Domain\Branches\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch closure/archival blocked by live operational records (Scope §3.3).
 *
 * Renders the Phase 3 envelope with code `branch_closure_blocked` and the list
 * of blocking conditions in `meta.blockers` so the UI can explain exactly what
 * must be resolved first.
 */
final class BranchClosureBlockedException extends Exception
{
    /** @param list<string> $blockers */
    private function __construct(private readonly array $blockers)
    {
        parent::__construct('Branch cannot be closed while live operational records exist.');
    }

    /** @param list<string> $blockers */
    public static function because(array $blockers): self
    {
        return new self($blockers);
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'branch_closure_blocked',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => ['blockers' => $this->blockers],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
