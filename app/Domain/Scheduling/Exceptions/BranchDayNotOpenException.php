<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A same-day client check-in was attempted while the branch business day is not
 * operationally open (Plan §25.2 Branch Day machine; Phase 16A). Renders the
 * Phase 3 envelope with the stable `branch_day_not_open` code (409) — the
 * appointment's state is unchanged. No internal ids are leaked.
 */
final class BranchDayNotOpenException extends Exception
{
    public static function make(): self
    {
        return new self('The branch business day must be open to check a client in.');
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'branch_day_not_open',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 409, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
