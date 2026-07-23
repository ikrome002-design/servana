<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Support;

/**
 * Redacts and bounds an SMS provider response before it is persisted to `sms_delivery_attempts`
 * or logged (Plan §24.5 redaction list; ADR-010; Phase 21S).
 *
 * A provider's error body is the one place a recipient's phone number, the message body or an API
 * credential can leak back into Servana's database — providers routinely echo the destination
 * MSISDN and the submitted text in an error. This strips anything that looks like a credential,
 * token, sender id, message body, email or phone number BEFORE truncating, so a secret cannot
 * survive by sitting past the cut, and then bounds the result to the column width.
 *
 * Defence in depth, three layers:
 *   1. this redactor, which removes labelled values and free-standing numbers/emails;
 *   2. {@see stripDigitRuns()}, which removes ANY remaining run of 7+ digits regardless of shape —
 *      so an unfamiliar international format still cannot survive;
 *   3. the `sms_delivery_attempts_redaction_check` DB CHECK, which rejects the row outright if a
 *      run of 7+ digits is still present.
 *
 * The redaction is deliberately aggressive: a lost diagnostic string is recoverable from the
 * provider's own dashboard, a leaked subscriber number is not.
 */
final class SmsProviderPayloadRedactor
{
    /** Key names whose VALUE is replaced wholesale wherever they appear in a JSON-ish body. */
    private const SENSITIVE_KEYS = [
        'apikey', 'api_key', 'authorization', 'auth', 'token', 'access_token', 'secret',
        'password', 'credential', 'signature', 'nonce', 'key',
        'phone', 'phone_number', 'phonenumber', 'msisdn', 'to', 'recipient', 'destination',
        'sender', 'sender_id', 'senderid', 'from',
        'message', 'text', 'body', 'content', 'email',
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
        // Long hex/base64-ish runs are what a signature or key looks like once its label is gone.
        $clean = (string) preg_replace('/\b[A-Fa-f0-9]{32,}\b/', '[redacted]', $clean);

        // Layer 2 — the catch-all. ANY run of 7+ digits (with optional +, spaces or hyphens inside)
        // is a phone number as far as this redactor is concerned.
        $clean = $this->stripDigitRuns($clean);

        $clean = (string) preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        if ($clean === '') {
            return null;
        }

        $max = (int) config('sms.delivery.response_body_max_chars', 512);

        return mb_substr($clean, 0, $max);
    }

    /**
     * Remove any run of 7 or more digits, however it is punctuated. Deliberately does NOT try to
     * recognise a country's dialling format — an unknown format must not be a way through.
     */
    public function stripDigitRuns(string $value): string
    {
        // Collapse separators inside candidate runs first, so "+254 712 345 678" is caught as one.
        $collapsed = (string) preg_replace_callback(
            '/\+?\d[\d\s\-().]{5,}\d/',
            static function (array $m): string {
                $digits = preg_replace('/\D+/', '', $m[0]) ?? '';

                return strlen($digits) >= 7 ? '[redacted-msisdn]' : $m[0];
            },
            $value,
        );

        // Anything still carrying 7+ consecutive digits (e.g. a bare id) is redacted too.
        return (string) preg_replace('/\d{7,}/', '[redacted-msisdn]', $collapsed);
    }
}
