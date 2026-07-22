<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Support;

/**
 * Redacts and bounds a partner response body before it is persisted to `re_event_deliveries`
 * (Plan §24.5 redaction list; §9 rule 23; Phase 21R-A).
 *
 * A partner's error body is useful for diagnosis and is also the one place partner-side data can
 * leak back into Servana's database. This strips anything that looks like a credential, signature,
 * token, key, nonce, referral code, MSISDN or email address BEFORE truncating, so a secret cannot
 * survive by sitting past the cut, and then bounds the result to the column width.
 *
 * The redaction is deliberately aggressive: a lost diagnostic string is recoverable from the
 * partner's own logs, a leaked secret is not.
 */
final class DeliveryResponseRedactor
{
    /** Key names whose VALUE is replaced wholesale wherever they appear in a JSON-ish body. */
    private const SENSITIVE_KEYS = [
        'signature', 'secret', 'token', 'key', 'key_id', 'api_key', 'apikey', 'authorization',
        'nonce', 'password', 'credential', 'access_token', 'refresh_token', 'referral_code', 'code',
        'phone', 'msisdn', 'email',
    ];

    public function redact(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $clean = trim($body);

        if ($clean === '') {
            return null;
        }

        // Control characters would make the stored evidence unreadable and can smuggle newlines
        // into anything that later renders it.
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $clean);

        // "key": "value"  /  key=value  — replace the VALUE, keep the key so the shape stays useful.
        foreach (self::SENSITIVE_KEYS as $key) {
            $quoted = preg_quote($key, '/');
            $clean = (string) preg_replace('/(["\']'.$quoted.'["\']\s*:\s*)("[^"]*"|\'[^\']*\'|[^,\}\s]+)/i', '$1"[redacted]"', $clean);
            $clean = (string) preg_replace('/\b'.$quoted.'\s*=\s*[^&\s,;\}]+/i', $key.'=[redacted]', $clean);
        }

        // Free-standing values that are sensitive regardless of the key that carried them.
        $clean = (string) preg_replace('/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/', '[redacted-email]', $clean);
        $clean = (string) preg_replace('/\bSERVANA-[A-Z0-9]{4,}\b/i', '[redacted-code]', $clean);
        $clean = (string) preg_replace('/\b(?:\+?254|0)7\d{8}\b/', '[redacted-msisdn]', $clean);
        // Long hex/base64-ish runs are what a signature or key looks like once its label is gone.
        $clean = (string) preg_replace('/\b[A-Fa-f0-9]{32,}\b/', '[redacted]', $clean);

        $clean = (string) preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        if ($clean === '') {
            return null;
        }

        $max = (int) config('refer-earn.delivery.response_body_max_chars', 512);

        return mb_substr($clean, 0, $max);
    }
}
