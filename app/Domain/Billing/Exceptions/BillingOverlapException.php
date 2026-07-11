<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An effective-dated billing row would overlap an existing one (Plan §13.9, §13.10; Phase 20A).
 * The PostgreSQL `EXCLUDE USING gist` constraint is the authoritative guard; the create/schedule
 * actions catch its violation (SQLSTATE 23P01) and rethrow this friendly 409 instead of leaking a
 * constraint error. `code` distinguishes the two overlap surfaces.
 */
final class BillingOverlapException extends Exception
{
    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function planPrice(): self
    {
        return new self('plan_price_overlap', 'A price already covers this plan, interval, currency and effective range.');
    }

    public static function preferredFeeRule(): self
    {
        return new self('preferred_personnel_fee_rule_overlap', 'An active or scheduled rule already covers this scope and effective range.');
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
        ], 409, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
