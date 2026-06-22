<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base for idempotency middleware failures (Plan §11.5, §24.3, §24.4; Phase R4).
 *
 * Each subclass maps to one structured outcome code and renders the cross-cutting
 * envelope directly (like the auth/MFA exceptions), with optional safe meta and
 * headers (e.g. Retry-After). Never exposes key hashes or internal ids.
 */
abstract class IdempotencyException extends Exception
{
    abstract public function errorCode(): string;

    abstract public function statusCode(): int;

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return [];
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        $response = response()->json([
            'error' => [
                'code' => $this->errorCode(),
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) $this->meta(),
            ],
        ], $this->statusCode(), [CorrelationIdMiddleware::HEADER => $correlationId]);

        foreach ($this->headers() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
