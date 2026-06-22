<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Exceptions\StepUpRequiredException;
use App\Domain\Auth\Mfa\MfaAuditLogger;
use App\Domain\Auth\Mfa\MfaSession;
use App\Domain\Auth\Mfa\StepUpAction;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reusable fresh-step-up gate for designated sensitive actions (Plan §18, §9.4
 * step 13; Phase R3).
 *
 * Attach with a centrally-defined {@see StepUpAction} value, e.g.
 * `RequireFreshMfa::class.':'.StepUpAction::RefundFinalization->value`, as the
 * LAST middleware before the controller (just before validation/execution). A
 * missing or stale MFA assertion → 403 `step_up_required` (re-challenge); a
 * fresh assertion continues.
 *
 * The action MUST be a registered classification — `StepUpAction::from()` fails
 * loudly on an unknown value so a route can never silently opt out of the
 * central registry.
 */
final class RequireFreshMfa
{
    public function __construct(
        private readonly MfaSession $session,
        private readonly MfaAuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        // Fail loudly if a route names an unregistered classification.
        $stepUp = StepUpAction::from($action);

        $user = $request->user();
        $fresh = $request->hasSession() && $this->session->isFresh($request->session());

        if (! $fresh) {
            if ($user instanceof User) {
                $this->audit->record(AuditEvent::MfaStepUpDenied, $user, ['action' => $stepUp->value]);
            }

            throw new StepUpRequiredException;
        }

        if ($user instanceof User) {
            $this->audit->record(AuditEvent::MfaStepUpSucceeded, $user, ['action' => $stepUp->value]);
        }

        return $next($request);
    }
}
