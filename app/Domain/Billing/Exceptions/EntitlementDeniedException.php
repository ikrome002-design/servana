<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Billing\ValueObjects\EntitlementDecision;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The merchant's plan does not include a required entitlement (Plan §20 "the gate returns 403 with
 * an upgrade-relevant code when the entitlement is absent or a limit is exceeded"; §11.5 envelope;
 * Phase 21S).
 *
 * The error CODE is the {@see EntitlementDecision} code — `no_active_plan`, `entitlement_absent`,
 * `entitlement_disabled` or `entitlement_limit_exceeded` — so the SPA can route the user to the
 * right remedy (subscribe vs upgrade) instead of showing a generic 403. `meta.entitlement` names
 * the entitlement key; no plan price, no internal id and no other merchant's data is disclosed.
 */
final class EntitlementDeniedException extends Exception
{
    private function __construct(
        private readonly string $errorCode,
        private readonly string $entitlementKey,
        private readonly ?int $limit,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function from(string $entitlementKey, EntitlementDecision $decision): self
    {
        $message = match ($decision->code) {
            EntitlementDecision::CODE_NO_PLAN => 'This merchant has no active subscription, so this feature is unavailable.',
            EntitlementDecision::CODE_LIMIT_EXCEEDED => 'Your plan limit for this feature has been reached.',
            default => 'Your current plan does not include this feature.',
        };

        return new self($decision->code, $entitlementKey, $decision->limit, $message);
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        $meta = ['entitlement' => $this->entitlementKey];

        if ($this->limit !== null) {
            $meta['limit'] = $this->limit;
        }

        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => $meta,
            ],
        ], 403, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
