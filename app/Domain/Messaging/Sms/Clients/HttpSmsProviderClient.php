<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Clients;

use App\Domain\Messaging\Sms\Clients\Dto\SmsSendResult;
use App\Domain\Messaging\Sms\Enums\SmsProviderResultClass;
use App\Domain\Messaging\Sms\Exceptions\SmsProviderConfigurationException;
use App\Domain\Messaging\Sms\Support\SmsProviderPayloadRedactor;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP transport to a configured SMS provider (Plan §64 "provider adapter interface"; Phase 21S).
 *
 * NOT REACHABLE WITHOUT COMPLETE CONFIGURATION. The container binds this class only when
 * `sms.enabled` is true AND every credential is present; {@see assertConfigured()} re-checks on
 * every call so a partly configured environment throws rather than sending an unauthenticated
 * request to a guessed endpoint. There is no default for any credential.
 *
 * The Plan pins no provider, so the wire shape here is the generic one recorded in REM-SMS-002 and
 * MUST be verified against the real provider sandbox before Phase 25:
 *   - `POST {base_url}/messages` with a bearer API key;
 *   - body `{to, from, text, reference}`;
 *   - `2xx` ⇒ accepted, with `message_id` / `cost_minor` read defensively.
 * Provider status codes are mapped onto the closed {@see SmsProviderResultClass} set; anything
 * unrecognised becomes `Unexpected`, which is retriable-with-cap, so an unknown provider behaviour
 * degrades to a dead letter rather than silently dropping a message.
 *
 * CONTACT PROTECTION: the destination number is used to build the request and is never logged,
 * never stored, and never returned. The response body is handed back RAW in
 * {@see SmsSendResult::$rawProviderMessage} and is redacted by
 * {@see SmsProviderPayloadRedactor} before it reaches the
 * database or a log line.
 */
final class HttpSmsProviderClient implements SmsProviderClientInterface
{
    public function send(string $phone, string $body, string $reference): SmsSendResult
    {
        $this->assertConfigured();

        $startedAt = microtime(true);

        try {
            $response = Http::withToken((string) config('sms.api_key'))
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Contract-Version' => (string) config('sms.contract_version'),
                ])
                ->timeout((int) config('sms.delivery.timeout_seconds', 10))
                ->post(rtrim((string) config('sms.base_url'), '/').'/messages', [
                    'to' => $phone,
                    'from' => (string) config('sms.sender_id'),
                    'text' => $body,
                    'reference' => $reference,
                ]);
        } catch (Throwable $e) {
            // A transport failure carries no provider body; the exception message may contain the
            // request URL, so only its class is retained.
            return SmsSendResult::failure(
                SmsProviderResultClass::TransportError,
                'transport_exception',
                $e::class,
                $this->elapsedMs($startedAt),
            );
        }

        $durationMs = $this->elapsedMs($startedAt);
        $payload = $this->decode($response->body());

        if ($response->successful()) {
            $messageId = $this->stringOrNull($payload['message_id'] ?? $payload['id'] ?? null);

            return new SmsSendResult(
                SmsProviderResultClass::Accepted,
                $messageId,
                'accepted',
                null,
                isset($payload['cost_minor']) && is_numeric($payload['cost_minor']) ? (int) $payload['cost_minor'] : null,
                $durationMs,
            );
        }

        return SmsSendResult::failure(
            $this->classify($response->status(), $this->stringOrNull($payload['error_code'] ?? null)),
            $this->stringOrNull($payload['error_code'] ?? null) ?? ('http_'.$response->status()),
            $response->body(),
            $durationMs,
        );
    }

    public function providerSlug(): string
    {
        return substr((string) config('sms.provider', 'http'), 0, 32);
    }

    /**
     * Every piece of the contract must be present. Missing anything throws — the adapter never
     * guesses an endpoint, a key, a sender id or a contract version.
     *
     * @throws SmsProviderConfigurationException
     */
    public function assertConfigured(): void
    {
        foreach (['sms.base_url', 'sms.api_key', 'sms.sender_id', 'sms.contract_version'] as $key) {
            $value = config($key);

            if (! is_string($value) || trim($value) === '') {
                throw SmsProviderConfigurationException::missing($key);
            }
        }
    }

    /**
     * Map an HTTP status (and the provider's own error code when it gives one) onto the closed
     * result-class set. Only the two recipient-scoped rejections are permanent.
     */
    private function classify(int $status, ?string $errorCode): SmsProviderResultClass
    {
        $code = strtolower((string) $errorCode);

        return match (true) {
            str_contains($code, 'opted_out'), str_contains($code, 'unsubscribed') => SmsProviderResultClass::OptedOut,
            str_contains($code, 'invalid_recipient'), str_contains($code, 'invalid_number') => SmsProviderResultClass::InvalidRecipient,
            str_contains($code, 'insufficient'), str_contains($code, 'balance') => SmsProviderResultClass::InsufficientBalance,
            $status === 401, $status === 403 => SmsProviderResultClass::Unauthorized,
            $status === 429 => SmsProviderResultClass::RateLimited,
            // A 422 without a recognised recipient code is a contract mismatch, not a bad number —
            // retry-with-cap so it dead-letters visibly instead of silently discarding a message.
            $status === 400, $status === 422 => SmsProviderResultClass::Unexpected,
            $status >= 500 => SmsProviderResultClass::ProviderError,
            default => SmsProviderResultClass::Unexpected,
        };
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : substr($string, 0, 64);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
