<?php

declare(strict_types=1);

namespace App\Domain\Audit\Exceptions;

use App\Domain\Audit\Enums\AuditFlaggedEventStatus;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Flagged-event review failures (Plan §13.2, §25, §80; Phase 19). Renders the Phase 3
 * error envelope with a canonical, safe code. Never leaks audit-row contents.
 */
final class AuditFlaggedEventException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(AuditFlaggedEventStatus $from, AuditFlaggedEventStatus $to): self
    {
        return new self('invalid_state_transition', "A flagged audit event cannot move from {$from->value} to {$to->value}.", 422);
    }

    /** A review note is required to resolve or dismiss a flag. */
    public static function reviewNoteRequired(): self
    {
        return new self('flagged_event_note_required', 'A review note is required to resolve or dismiss a flagged event.', 422);
    }

    /** Only branch-scoped audit rows (non-null merchant + branch) are flaggable. */
    public static function notBranchScoped(): self
    {
        return new self('audit_event_not_flaggable', 'Only a branch-scoped audit event can be flagged for review.', 422);
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
