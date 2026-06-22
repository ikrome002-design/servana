<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Support;

use App\Domain\Idempotency\Models\IdempotencyKey;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Captures a replay-safe view of a response and rebuilds it on replay
 * (Plan §24.4 steps 6–7, §24.5; Phase R4).
 *
 * Only the HTTP status, the JSON body, and a tiny header allowlist are persisted.
 * Streamed/binary/non-JSON responses are never cached. The forbidden list is kept
 * explicit so the security test can assert none of these are ever stored.
 */
final class ReplayResponseSanitizer
{
    /** The ONLY response headers persisted/replayed. */
    public const HEADER_ALLOWLIST = ['content-type'];

    /** Never stored or replayed (asserted by ReplayResponseSecurityTest). */
    public const FORBIDDEN_HEADERS = [
        'set-cookie', 'cookie', 'authorization', 'proxy-authorization',
        'x-xsrf-token', 'x-csrf-token', 'content-security-policy',
        'content-security-policy-report-only', 'server', 'x-powered-by',
    ];

    /**
     * Capture a replay-safe payload, or null when the response must not be cached
     * (streamed, binary, or non-JSON).
     *
     * @return array{status: int, headers: array<string, string>, body: array<mixed>}|null
     */
    public function capture(Response $response): ?array
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if (! str_contains($contentType, 'application/json')) {
            return null;
        }

        $decoded = json_decode((string) $response->getContent(), true);
        if (! is_array($decoded)) {
            return null;
        }

        $headers = [];
        foreach (self::HEADER_ALLOWLIST as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => $decoded,
        ];
    }

    /** Rebuild the stored response for replay, tagged with a safe replay marker. */
    public function toResponse(IdempotencyKey $row): JsonResponse
    {
        $response = new JsonResponse(
            $row->response_body_encrypted ?? [],
            $row->response_status ?? Response::HTTP_OK,
        );

        foreach (($row->response_headers ?? []) as $name => $value) {
            if (in_array(strtolower((string) $name), self::HEADER_ALLOWLIST, true)) {
                $response->headers->set((string) $name, (string) $value);
            }
        }

        // Safe indicator that this is a replay; never exposes key hashes or ids.
        $response->headers->set('Idempotent-Replay', 'true');

        return $response;
    }
}
