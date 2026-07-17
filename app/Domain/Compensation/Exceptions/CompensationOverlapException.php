<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An effective compensation window would overlap an existing one (Plan §59; Scope §12.9 "one
 * active compensation plan per personnel per branch"; Phase 20F, F3).
 *
 * The PostgreSQL partial `EXCLUDE USING gist` over active+scheduled is the AUTHORITATIVE guard;
 * the approve action catches its violation (SQLSTATE 23P01) and rethrows this friendly 409 instead
 * of leaking a constraint error. The domain pre-check + advisory lock only make the failure
 * friendlier and serialize concurrent approvals — they never replace the database guard.
 */
final class CompensationOverlapException extends Exception
{
    public static function compensationPlan(): self
    {
        return new self('An active or scheduled compensation plan already covers this personnel and effective range in this branch.');
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'compensation_plan_overlap',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 409, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
