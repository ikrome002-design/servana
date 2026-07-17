<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A Phase-20F reference points outside the acting tenant/branch scope (Plan §59; ADR-002;
 * guardrail §6.3). HR is same-branch only, so a staff profile or commission rule from another
 * merchant/branch must be indistinguishable from one that does not exist: this renders **404**,
 * never 403 — a 403 would confirm the row exists to an unauthorized caller.
 *
 * The composite FKs are the authoritative guard; this is the friendly, typed surface.
 * Messages never echo the foreign identifier.
 */
final class CompensationScopeException extends Exception
{
    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function staffProfile(): self
    {
        return new self('staff_profile_scope_mismatch', 'The requested personnel was not found in this branch.');
    }

    public static function commissionRule(): self
    {
        return new self('commission_rule_scope_mismatch', 'The requested commission rule was not found in this branch.');
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
        ], 404, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
