<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders any throwable as the structured API error envelope (Plan §11.5):
 *   { "error": { "code", "message", "fields", "meta" } }
 *
 * 5xx responses carry only a generic message plus the correlation id in meta —
 * never stack traces, SQL, file paths, tokens or internal messages (Plan §11.5).
 */
final class ApiErrorRenderer
{
    public function __construct(private readonly CorrelationId $correlationId) {}

    /** Only API / JSON requests get the envelope; web requests keep default rendering. */
    public function shouldHandle(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }

    public function render(Throwable $e, Request $request): JsonResponse
    {
        [$code, $status, $message, $fields] = $this->map($e);

        $meta = [];
        if ($status >= 500) {
            $meta['correlation_id'] = $this->correlationId->get();
        }

        return response()->json([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'fields' => (object) $fields,
                'meta' => (object) $meta,
            ],
        ], $status, [CorrelationIdMiddleware::HEADER => (string) $this->correlationId->get()]);
    }

    /**
     * @return array{0: ErrorCode, 1: int, 2: string, 3: array<string, mixed>}
     */
    private function map(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return [ErrorCode::ValidationFailed, 422, $e->getMessage(), $e->errors()];
        }

        if ($e instanceof AuthenticationException) {
            return [ErrorCode::Unauthenticated, 401, $this->defaultMessage(ErrorCode::Unauthenticated), []];
        }

        if ($e instanceof AuthorizationException) {
            return [ErrorCode::PermissionDenied, 403, $this->defaultMessage(ErrorCode::PermissionDenied), []];
        }

        if ($e instanceof ModelNotFoundException) {
            return [ErrorCode::NotFound, 404, $this->defaultMessage(ErrorCode::NotFound), []];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = ErrorCode::fromHttpStatus($status);

            // For 5xx HttpExceptions, never surface the (potentially sensitive)
            // exception message — fall back to the generic internal message.
            return [$code, $status, $this->defaultMessage($code), []];
        }

        return [ErrorCode::InternalError, 500, $this->defaultMessage(ErrorCode::InternalError), []];
    }

    private function defaultMessage(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::ValidationFailed => 'The given data was invalid.',
            ErrorCode::Unauthenticated => 'Unauthenticated.',
            ErrorCode::PermissionDenied => 'This action is unauthorized.',
            ErrorCode::NotFound => 'Resource not found.',
            ErrorCode::MethodNotAllowed => 'The method is not allowed for this route.',
            ErrorCode::Conflict => 'The request conflicts with the current state.',
            ErrorCode::RateLimited => 'Too many requests.',
            ErrorCode::ServiceUnavailable => 'Service temporarily unavailable.',
            ErrorCode::InternalError => 'An unexpected error occurred.',
        };
    }
}
