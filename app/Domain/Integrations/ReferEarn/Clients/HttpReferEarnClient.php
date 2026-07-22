<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Clients;

use App\Domain\Integrations\ReferEarn\Clients\Dto\AttributionConfirmation;
use App\Domain\Integrations\ReferEarn\Clients\Dto\EventDeliveryResult;
use App\Domain\Integrations\ReferEarn\Clients\Dto\ReferralCodeValidation;
use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryResponseClass;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Integrations\ReferEarn\Support\CitrusEventSigner;
use App\Domain\Integrations\ReferEarn\Support\DeliveryResponseRedactor;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Real HTTP transport to Citrus R&E (Plan §58A.2, §17.1; ADR-015; Phase 21R-A).
 *
 * Bound ONLY when the integration is explicitly enabled and fully configured; otherwise the
 * container binds `FakeReferEarnClient` (see ReferEarnServiceProvider), which is why CI never
 * reaches a live partner (Plan §81 rule 21).
 *
 * Two hard rules shape this class:
 *
 *  1. **It never throws for a partner failure.** An unreachable or unhappy R&E is an expected
 *     outcome that the outbox must record and retry — not an exception that could bubble into a
 *     merchant-facing request (Plan A-19).
 *  2. **Nothing sensitive escapes.** Response bodies pass through `DeliveryResponseRedactor` before
 *     they leave this class, and the request's signature, nonce, key id and secret are never
 *     returned, logged or stored (Plan §24.5).
 *
 * The body is sent as raw pre-encoded canonical JSON — re-encoding it here would change the bytes
 * and break `content_sha256`.
 */
final class HttpReferEarnClient implements ReferEarnClientInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CitrusEventSigner $signer,
        private readonly DeliveryResponseRedactor $redactor,
    ) {}

    public function deliverEvent(ReOutboundEvent $event, string $body): EventDeliveryResult
    {
        $path = $this->eventsPath();
        $startedAt = microtime(true);

        try {
            $headers = $this->signer->headers($event, 'POST', $path, $body);

            $response = $this->http
                ->timeout($this->timeout())
                ->withHeaders($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->withBody($body, 'application/json')
                ->post($this->url($path));

            return new EventDeliveryResult(
                class: ReDeliveryResponseClass::fromHttpStatus($response->status()),
                status: $response->status(),
                errorCode: $this->errorCodeFrom($response),
                redactedBody: $this->redactor->redact($response->body()),
                durationMs: $this->elapsedMs($startedAt),
                retryAfterSeconds: $this->retryAfterFrom($response),
            );
        } catch (Throwable $e) {
            // Transport failure, or a signing failure (unpinned algorithm / missing key). Both are
            // "no delivery happened"; the class distinguishes them for the operator without
            // exposing the exception message, which could quote configuration.
            return new EventDeliveryResult(
                class: ReDeliveryResponseClass::TransportError,
                status: null,
                errorCode: class_basename($e),
                redactedBody: null,
                durationMs: $this->elapsedMs($startedAt),
            );
        }
    }

    public function validateReferralCode(string $normalizedCode, string $snapshotUlid): ReferralCodeValidation
    {
        try {
            $response = $this->companionCall('/referral-codes/validate', [
                'referral_code' => $normalizedCode,
                'source_reference' => $snapshotUlid,
            ]);
        } catch (Throwable) {
            return ReferralCodeValidation::retryable();
        }

        $resultCode = $this->resultCodeFrom($response);

        return match (true) {
            $response->successful() => ReferralCodeValidation::valid($resultCode),
            // 4xx that is not a rate limit is a verdict; 429 and 5xx are "no answer yet".
            $response->status() === 429, $response->serverError() => ReferralCodeValidation::retryable($resultCode),
            default => ReferralCodeValidation::invalid($resultCode),
        };
    }

    public function confirmAttribution(string $normalizedCode, string $snapshotUlid, string $merchantPublicId): AttributionConfirmation
    {
        try {
            // Idempotent by snapshot ULID (Plan §58A.2), so a retry after an ambiguous network
            // failure cannot create a second attribution.
            $response = $this->companionCall('/attributions/confirm', [
                'referral_code' => $normalizedCode,
                'source_reference' => $snapshotUlid,
                'source_tenant_id' => $merchantPublicId,
            ], $snapshotUlid);
        } catch (Throwable) {
            return AttributionConfirmation::retryable();
        }

        $resultCode = $this->resultCodeFrom($response);

        if ($response->status() === 429 || $response->serverError()) {
            return AttributionConfirmation::retryable($resultCode);
        }

        if (! $response->successful()) {
            return AttributionConfirmation::rejected($resultCode);
        }

        $attributionId = $response->json('attribution_public_id');

        // A "success" with no attribution id is not a confirmation — fail closed rather than
        // storing a null id that would later read as confirmed-without-evidence.
        return is_string($attributionId) && $attributionId !== ''
            ? AttributionConfirmation::confirmed($attributionId, $resultCode)
            : AttributionConfirmation::retryable($resultCode);
    }

    /** @param array<string, string> $payload */
    private function companionCall(string $suffix, array $payload, ?string $idempotencyKey = null): Response
    {
        $headers = ['Accept' => 'application/json'];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->http
            ->timeout($this->timeout())
            ->withHeaders($headers)
            ->asJson()
            ->post($this->url($this->productPath().$suffix), $payload);
    }

    private function errorCodeFrom(Response $response): ?string
    {
        if ($response->successful()) {
            return null;
        }

        $code = $response->json('error.code') ?? $response->json('code');

        return is_string($code) && $code !== '' ? mb_substr($code, 0, 64) : null;
    }

    private function resultCodeFrom(Response $response): ?string
    {
        $code = $response->json('result_code') ?? $response->json('error.code') ?? $response->json('code');

        return is_string($code) && $code !== '' ? mb_substr($code, 0, 64) : null;
    }

    private function retryAfterFrom(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(0, (int) $header) : null;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function productPath(): string
    {
        return '/api/v1/integrations/products/'.(string) config('refer-earn.product_code', 'SRV');
    }

    private function eventsPath(): string
    {
        return $this->productPath().'/events';
    }

    private function url(string $path): string
    {
        return rtrim((string) config('refer-earn.base_url'), '/').$path;
    }

    private function timeout(): int
    {
        return (int) config('refer-earn.delivery.timeout_seconds', 10);
    }
}
