<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid referral-snapshot transition (Plan §25.6, §58A.1; Phase 21R-A). Renders the canonical
 * `invalid_state_transition` (422) envelope for consistency with the rest of the platform, even
 * though Phase 21R-A exposes no route that can reach it — the snapshot machine is driven by the
 * registration transaction and by queued jobs, so this exception normally fails a job rather than a
 * request. Messages carry status names only: never the referral code (Plan §24.5), never a referrer
 * identity (Servana has none).
 */
final class ReferralSnapshotStateException extends Exception
{
    public static function invalidTransition(string $from, string $to): self
    {
        return new self("A referral snapshot cannot move from {$from} to {$to}.");
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
