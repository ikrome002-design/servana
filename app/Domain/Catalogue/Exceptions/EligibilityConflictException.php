<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An active personnel-service eligibility already exists for the (service,
 * personnel) pair (Plan §39; Phase 15A). Renders a deterministic 409 envelope
 * with code `eligibility_exists` so the UI can show "already eligible" rather
 * than duplicating the assignment.
 */
final class EligibilityConflictException extends Exception
{
    public static function alreadyActive(): self
    {
        return new self('This personnel member is already eligible for this service.');
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'eligibility_exists',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 409, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
