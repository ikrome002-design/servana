<?php

declare(strict_types=1);

namespace App\Domain\Clients\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A client with the same normalized phone already exists, active, in this branch
 * (Plan §35; Phase 15A). Renders a deterministic 409 envelope with code
 * `duplicate_client` and the existing client's ULID in `meta.client_id` so the UI
 * can offer to open the existing record. The full phone is NEVER echoed.
 */
final class DuplicateClientException extends Exception
{
    private function __construct(private readonly string $existingClientUlid)
    {
        parent::__construct('A client with this phone number already exists in this branch.');
    }

    public static function forExisting(string $existingClientUlid): self
    {
        return new self($existingClientUlid);
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'duplicate_client',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => ['client_id' => $this->existingClientUlid],
            ],
        ], 409, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
