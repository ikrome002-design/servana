<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every selected client was excluded, so there is nothing to send (Plan §64; Phase 21S).
 *
 * A campaign is NEVER created with zero recipients — a `confirmed` campaign always has at least
 * one eligible recipient (the `personnel_sms_campaigns_recipient_count_check` DB CHECK enforces
 * the same rule), so an empty send can neither be queued nor billed.
 *
 * `meta.excluded` carries the reason-code → count map only. Per ADR-010 it never names a client,
 * so this response cannot be used to probe which specific ULIDs exist.
 */
final class NoEligibleSmsRecipientsException extends Exception
{
    /** @param array<string, int> $exclusionCounts */
    public function __construct(private readonly array $exclusionCounts)
    {
        parent::__construct('None of the selected clients can receive this message.');
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'no_eligible_recipients',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => ['excluded' => (object) $this->exclusionCounts],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
