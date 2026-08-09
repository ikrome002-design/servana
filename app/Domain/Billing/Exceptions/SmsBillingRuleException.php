<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SMS pricing-rule lifecycle failures (COR-UI08-001 §9; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-sms-billing-rule.md.
 *
 * The database is the authoritative guard in every case — `UNIQUE(effective_from)` for overlap and
 * `platform_sms_billing_rules_guard` for immutability and the pending-only cancellation. These
 * factories turn those violations into the Phase 3 error envelope with a canonical, safe code
 * instead of leaking a SQLSTATE or a trigger message.
 */
final class SmsBillingRuleException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /** Two rules may never claim the same effective instant. */
    public static function overlappingEffectiveInstant(): self
    {
        return new self(
            'sms_billing_rule_overlap',
            'Another SMS billing rule already takes effect at that instant. Choose a different effective date.',
            409,
        );
    }

    /**
     * Backdating is refused. It could not rewrite a charge — sms_billing_entries is frozen by its
     * own trigger — but it would make the recorded pricing history untruthful.
     */
    public static function backdated(): self
    {
        return new self(
            'invalid_state_transition',
            'An SMS billing rule cannot be scheduled in the past; the pricing series is append-only.',
            422,
        );
    }

    /** Only a rule that has not yet taken effect may be withdrawn. */
    public static function alreadyEffective(): self
    {
        return new self(
            'invalid_state_transition',
            'This SMS billing rule has already taken effect and is permanent history; schedule a new rule instead.',
            422,
        );
    }

    public static function alreadyCancelled(): self
    {
        return new self(
            'invalid_state_transition',
            'This SMS billing rule was already cancelled.',
            422,
        );
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
