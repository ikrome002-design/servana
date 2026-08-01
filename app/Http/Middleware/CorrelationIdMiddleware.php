<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation id to every request for end-to-end tracing
 * (Plan §11.5, §22.1). An inbound X-Correlation-ID is honoured only when it is
 * safe and length-bounded; otherwise a fresh ULID is generated. The id is
 * attached to the request, the correlation holder (for logs + error envelopes)
 * and the response header.
 */
final class CorrelationIdMiddleware
{
    public const HEADER = 'X-Correlation-ID';

    /**
     * The widest correlation id that can actually be STORED.
     *
     * `audit_logs.correlation_id` is `character(26)` — sized for the ULID this middleware mints.
     * The bound here must therefore be 26, not some larger "looks safe" number: anything longer
     * is accepted at the boundary, carried all the way to the audit write, and then rejected by
     * PostgreSQL with SQLSTATE 22001, which surfaces as a 500 on every audited request.
     *
     * That is not hypothetical. It is what the Phase UI-03 deployed-origin browser proof hit on
     * the real nginx edge: `docker/nginx/default.conf` falls back to nginx's `$request_id`, a
     * 32-character hex string, whenever the client sends no `X-Correlation-ID`. Every audited
     * request through the edge therefore 500'd. No backend test could catch it, because the test
     * client never passes through nginx and the app's own ULID already fits.
     *
     * A client-supplied header hits the same path, so this is also an availability defect
     * reachable by anyone: `X-Correlation-ID: <27 characters>` was enough to 500 an audited
     * endpoint. Bounding the untrusted value to what the system can hold is the boundary's job.
     */
    private const MAX_LENGTH = 26;

    public function __construct(private readonly CorrelationId $correlationId) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);

        $id = $this->isSafe($incoming) ? (string) $incoming : (string) Str::ulid();

        $this->correlationId->set($id);
        $request->attributes->set('correlation_id', $id);
        $request->headers->set(self::HEADER, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /**
     * Accept only short, opaque tokens (ULIDs/UUIDs/trace ids). Rejecting
     * everything else prevents header/log injection via an untrusted value.
     */
    private function isSafe(?string $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= self::MAX_LENGTH
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }
}
