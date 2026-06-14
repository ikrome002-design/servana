<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-context access denials (Plan §8.1, §11.5).
 *
 * Carries a domain-specific stable code (`no_tenant_context`,
 * `merchant_suspended`, `pending_setup_only`, `setup_already_completed`) that is
 * narrower than the cross-cutting ErrorCode enum, so — like
 * InvalidMagicLinkException — it renders the Phase 3 envelope itself rather than
 * widening that enum. The HTTP status is the security signal (403/409); the code
 * lets the SPA route the user appropriately (e.g. pending_setup → wizard).
 */
final class TenantAccessException extends Exception
{
    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function noTenantContext(): self
    {
        return new self(
            'no_tenant_context',
            'No active merchant membership for this account.',
            403,
        );
    }

    public static function merchantSuspended(): self
    {
        return new self(
            'merchant_suspended',
            'This merchant account is not active. Access is restricted.',
            403,
        );
    }

    public static function pendingSetupOnly(): self
    {
        return new self(
            'pending_setup_only',
            'Complete first-time setup before accessing the dashboard.',
            403,
        );
    }

    public static function setupAlreadyCompleted(): self
    {
        return new self(
            'setup_already_completed',
            'First-time setup has already been completed for this merchant.',
            409,
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
