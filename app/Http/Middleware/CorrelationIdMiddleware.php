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

    private const MAX_LENGTH = 64;

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
