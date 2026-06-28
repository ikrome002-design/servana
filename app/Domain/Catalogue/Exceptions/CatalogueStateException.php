<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid catalogue state transition (Plan §25, guardrail §6.7; Phase 15A).
 *
 * Renders the Phase 3 envelope with the canonical `invalid_state_transition`
 * code (422) — e.g. archiving an already-archived service. Status transitions go
 * through the domain actions; an invalid transition is rejected here, never by a
 * silent no-op.
 */
final class CatalogueStateException extends Exception
{
    public static function alreadyArchived(string $entity): self
    {
        return new self("This {$entity} is already archived.");
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'invalid_state_transition',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
