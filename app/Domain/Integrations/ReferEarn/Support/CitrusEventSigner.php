<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Support;

use App\Domain\Integrations\ReferEarn\Exceptions\ReferEarnSigningException;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Signs outbound R&E events (Plan §9 rule 22, §58A.2, §17.1; ADR-015; Phase 21R-A).
 *
 * Canonical string (Plan §9 rule 22, verbatim):
 *
 *     METHOD \n PATH \n TIMESTAMP \n NONCE \n CONTENT_SHA256 \n EVENT_ID \n EVENT_TYPE \n EVENT_VERSION
 *
 * ALGORITHM-AWARE AND FAIL-CLOSED (ADR-015). The routine is selected by the configured algorithm
 * identifier; HMAC-SHA-256 is NOT assumed. An absent or unrecognised algorithm raises rather than
 * falling back to a default, because "silently signed with the wrong algorithm" is
 * indistinguishable from "forged" at the partner and would be discovered only in production.
 *
 * The signing timestamp is DELIVERY time, not the event's business `occurred_at` — R&E's tolerance
 * window applies to the signing timestamp only (Plan §58B.5 R-21).
 *
 * Nothing here is ever logged: Plan §24.5 forbids logging R&E signing keys, `X-Citrus-Signature`
 * values and nonces paired with signatures.
 */
final class CitrusEventSigner
{
    /** Algorithms Servana can actually compute. Extend only alongside a recorded contract pin. */
    private const SUPPORTED = ['hmac-sha256' => 'sha256'];

    /**
     * Build the full `X-Citrus-*` header set for one delivery attempt.
     *
     * @param  string  $body  the canonical JSON body EXACTLY as it will be sent
     * @return array<string, string>
     */
    public function headers(ReOutboundEvent $event, string $method, string $path, string $body, ?Carbon $signedAt = null): array
    {
        $keyId = $this->requireString('refer-earn.signing.key_id', 'signing key id');
        $algorithm = $this->requireString('refer-earn.signing.algorithm', 'signing algorithm identifier');
        $secret = $this->requireString('refer-earn.signing.secret', 'signing secret');

        $contentSha256 = hash('sha256', $body);

        // The hash Servana signs must be the hash it committed at outbox-insert time. If they differ,
        // something rebuilt the payload between insert and delivery — exactly the drift that would
        // surface at R&E as a 409 tamper signal. Fail here instead, with the evidence intact.
        if (! hash_equals($event->content_sha256, $contentSha256)) {
            throw ReferEarnSigningException::contentHashMismatch($event->event_id);
        }

        $timestamp = ($signedAt ?? now())->utc()->toIso8601ZuluString();
        $nonce = (string) Str::ulid();

        $signature = $this->sign(
            $this->canonicalString($method, $path, $timestamp, $nonce, $contentSha256, $event),
            $algorithm,
            $secret,
        );

        return [
            'X-Citrus-Key-Id' => $keyId,
            'X-Citrus-Event-Id' => $event->event_id,
            'X-Citrus-Event-Type' => $event->event_type->value,
            'X-Citrus-Event-Version' => $event->event_version,
            'X-Citrus-Timestamp' => $timestamp,
            'X-Citrus-Nonce' => $nonce,
            'X-Citrus-Content-SHA256' => $contentSha256,
            'X-Citrus-Signature' => $signature,
            // Plan §58A.2: the event id IS the idempotency key, so a duplicate delivery after a
            // network ambiguity is deduped by R&E rather than double-counted (§58B.5 R-09).
            'Idempotency-Key' => $event->event_id,
        ];
    }

    /** Plan §9 rule 22 canonical string. Exposed so signing vectors can assert it byte-for-byte. */
    public function canonicalString(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $contentSha256,
        ReOutboundEvent $event,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $contentSha256,
            $event->event_id,
            $event->event_type->value,
            $event->event_version,
        ]);
    }

    /** Compute the signature for an already-built canonical string. */
    public function sign(string $canonicalString, string $algorithm, string $secret): string
    {
        $hashAlgorithm = self::SUPPORTED[strtolower($algorithm)] ?? null;

        if ($hashAlgorithm === null) {
            throw ReferEarnSigningException::unsupportedAlgorithm($algorithm);
        }

        return hash_hmac($hashAlgorithm, $canonicalString, $secret);
    }

    /** @return list<string> */
    public function supportedAlgorithms(): array
    {
        return array_keys(self::SUPPORTED);
    }

    private function requireString(string $configKey, string $label): string
    {
        $value = config($configKey);

        if (! is_string($value) || trim($value) === '') {
            throw ReferEarnSigningException::missingConfiguration($label);
        }

        return $value;
    }
}
