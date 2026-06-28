<?php

declare(strict_types=1);

namespace App\Domain\Clients\Support;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Keyed HMAC blind index over a normalized client phone (Plan §35; guardrail
 * §6.4; Phase 15A).
 *
 * The blind index enables EXACT branch-scoped phone search and duplicate
 * prevention WITHOUT storing a plaintext phone index and WITHOUT a reversible
 * deterministic ciphertext. It is HMAC-SHA256(normalized_phone) under a dedicated
 * env-backed secret (`CLIENT_CONTACT_INDEX_KEY`), independent of APP_KEY so it can
 * be re-keyed. The digest is one-way: it cannot recover the phone number.
 *
 * The index is NEVER returned by the API (the model hides `phone_index`) and is
 * redacted from logs. A blind index is NOT a substitute for encryption of the
 * value itself — the phone is separately encrypted at rest.
 */
final class ClientContactIndex
{
    /** HMAC-SHA256 hex digest (64 chars) of the normalized phone. */
    public static function for(string $rawPhone): string
    {
        $normalized = PhoneNumberNormalizer::normalize($rawPhone);

        return hash_hmac('sha256', $normalized, self::key());
    }

    /**
     * Resolve the index key. Production MUST set CLIENT_CONTACT_INDEX_KEY (base64:
     * 32 bytes). Non-production falls back to a domain-separated derivation of
     * APP_KEY so local/test runs work without extra setup — never used in prod.
     */
    private static function key(): string
    {
        /** @var string|null $configured */
        $configured = Config::get('servana.clients.contact_index_key');

        if (is_string($configured) && $configured !== '') {
            return self::decodeBase64Key($configured);
        }

        if (Config::get('app.env') === 'production') {
            throw new RuntimeException(
                'CLIENT_CONTACT_INDEX_KEY must be set in production for the client phone blind index.'
            );
        }

        /** @var string $appKey */
        $appKey = (string) Config::get('app.key');
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is not set; cannot derive a client contact index key.');
        }

        // Domain-separated HKDF derivation so the blind-index key is distinct from APP_KEY.
        return hash_hkdf('sha256', self::decodeBase64Key($appKey), 32, 'servana-client-contact-index');
    }

    /** Accept `base64:...` (Laravel key format) or a raw secret string. */
    private static function decodeBase64Key(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('Client contact index key is not valid base64.');
            }

            return $decoded;
        }

        return $key;
    }
}
