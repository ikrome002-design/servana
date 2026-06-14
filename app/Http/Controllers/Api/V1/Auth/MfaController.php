<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * MFA (TOTP) placeholder (Plan §9.2, AS-2).
 *
 * MFA is an OPTIONAL, future add-on for Super Admin / Merchant Admin / Finance /
 * Audit and is NOT active at launch. No weak/partial TOTP is shipped here — the
 * real flow (encrypted secret, enrolment, `mfa_required` verify step, 5/5min
 * throttle) arrives with the account model that owns those roles.
 *
 * This contract endpoint exists so the route surface and SPA can be wired
 * defensively; it always reports MFA as not enabled. It is intentionally not
 * registered as a live route in Phase 5 (see routes/api.php).
 */
final class MfaController extends Controller
{
    public function verify(): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'mfa_not_enabled',
                'message' => 'Multi-factor authentication is not enabled.',
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], Response::HTTP_NOT_FOUND, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
