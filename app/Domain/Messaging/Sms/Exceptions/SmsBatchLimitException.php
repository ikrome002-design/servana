<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A composition limit was exceeded (Plan §64 configurable max batch / char + segment limit;
 * Phase 21S). Renders the Plan §11.5 envelope as 422 `validation_failed` with the offending field,
 * so the frontend can surface it inline. Messages carry counts only — never a client identity and
 * never a contact.
 */
final class SmsBatchLimitException extends Exception
{
    private function __construct(
        string $message,
        private readonly string $field,
        private readonly int $actual,
        private readonly int $limit,
    ) {
        parent::__construct($message);
    }

    public static function tooManyRecipients(int $actual, int $limit): self
    {
        return new self(
            "An SMS campaign can include at most {$limit} recipients.",
            'client_ulids',
            $actual,
            $limit,
        );
    }

    public static function messageTooLong(int $actual, int $limit): self
    {
        return new self(
            "An SMS message can be at most {$limit} characters.",
            'message_body',
            $actual,
            $limit,
        );
    }

    public static function tooManySegments(int $actual, int $limit): self
    {
        return new self(
            "An SMS message can be at most {$limit} segments.",
            'message_body',
            $actual,
            $limit,
        );
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $this->getMessage(),
                'fields' => [$this->field => [$this->getMessage()]],
                'meta' => ['actual' => $this->actual, 'limit' => $this->limit],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
