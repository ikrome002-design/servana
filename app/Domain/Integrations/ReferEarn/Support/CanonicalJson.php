<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Support;

use JsonException;
use RuntimeException;

/**
 * Canonical JSON encoding for outbound R&E event bodies (Plan §58A.2: "Event bodies are canonical
 * JSON (sorted keys, no insignificant whitespace) so `content_sha256` is deterministic"; §9 rule 22;
 * Phase 21R-A).
 *
 * Determinism is the whole point: the bytes hashed at outbox-insert time must be byte-identical to
 * the bytes signed and sent on attempt 1 and on attempt 12, days later, from a different worker.
 * That is what makes "same event id + same body hash across retries" a guarantee and what makes
 * R&E's `409 EVENT_ID_PAYLOAD_MISMATCH` a genuine tamper signal rather than an encoder artefact.
 *
 * Rules:
 *   - object keys sorted ascending by byte value, recursively;
 *   - no insignificant whitespace;
 *   - slashes and unicode left unescaped (stable, shortest form);
 *   - lists preserve order (order IS meaning in a list) and are never key-sorted;
 *   - floats are rejected — Servana money is integer minor units (ADR-005) and a float would make
 *     the encoding platform-dependent.
 */
final class CanonicalJson
{
    /** @param array<array-key, mixed> $payload */
    public static function encode(array $payload): string
    {
        try {
            $encoded = json_encode(
                self::normalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Outbound R&E payload is not encodable as canonical JSON.', 0, $e);
        }

        return $encoded;
    }

    /**
     * Lowercase hex SHA-256 over the canonical encoding.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function sha256(array $payload): string
    {
        return hash('sha256', self::encode($payload));
    }

    /**
     * Recursively sort object keys; leave list order alone; reject non-deterministic scalars.
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new RuntimeException('Outbound R&E payloads may not contain floats (ADR-005: integer minor units).');
        }

        if (is_object($value)) {
            throw new RuntimeException('Outbound R&E payloads must be built from arrays and scalars only.');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $normalized = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $normalized[$key] = self::normalize($value[$key]);
        }

        return $normalized;
    }
}
