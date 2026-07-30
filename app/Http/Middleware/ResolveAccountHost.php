<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Hosts\AccountHost;
use App\Http\Hosts\AccountHostResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the browser account host for SPA shell requests (Phase UI-02; ADR-016, ADR-017).
 *
 * On success it binds the resolved {@see AccountHost} into the container so the shell can
 * render the right account experience.
 *
 * On failure it returns a safe, non-enumerating denial. It does NOT redirect to a "correct"
 * host: that would leak which hosts exist and could silently move a user toward a broader
 * account experience (UI/UX plan §5.4).
 *
 * This middleware never authorizes anything. A request that gets past it is exactly as
 * unauthenticated and unauthorized as it was before (ADR-017).
 */
final class ResolveAccountHost
{
    public function __construct(private readonly AccountHostResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $accountHost = $this->resolver->resolve($request);

        if ($accountHost === null) {
            return $this->deny($request);
        }

        // Bound as an instance so controllers/views can type-hint it. Deliberately NOT
        // merged into the request's user, session or any authorization context.
        app()->instance(AccountHost::class, $accountHost);

        return $next($request);
    }

    private function deny(Request $request): Response
    {
        // Log the rejection with a correlation id and a REDACTED host. The raw value is
        // attacker-controlled and may carry injection payloads, so only a bounded, hashed
        // form is recorded — enough to correlate a scan, never enough to replay one.
        $raw = (string) $request->headers->get('host', '');

        Log::warning('account_host.rejected', [
            'correlation_id' => $request->headers->get('X-Correlation-Id'),
            'host_sha256' => $raw === '' ? null : substr(hash('sha256', $raw), 0, 16),
            'host_length' => mb_strlen($raw),
            'environment' => app()->environment(),
            'failure_category' => 'unapproved_account_host',
        ]);

        return response()->view('errors.unknown-host', [], Response::HTTP_MISDIRECTED_REQUEST)
            ->header('Cache-Control', 'no-store');
    }
}
