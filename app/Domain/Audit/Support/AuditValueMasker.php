<?php

declare(strict_types=1);

namespace App\Domain\Audit\Support;

/**
 * Centralized, recursive audit-value masker (Plan §9.13, §74; ADR-008).
 *
 * Applied SERVER-SIDE to audit `context` (and `actor_label`) at read time so no
 * endpoint can return raw PII/identifiers, and used at WRITE time by the auth
 * recorder so a full email is never persisted for pre-auth events (enumeration
 * resistance). Secrets must never be stored in the first place — this is
 * defense-in-depth for the accurate-but-sensitive values that legitimately live
 * in an audit row (emails, phones, references).
 *
 * Masking is by key name and applied recursively to nested arrays. Unknown keys
 * pass through unchanged; unknown nested structures are still recursed.
 */
final class AuditValueMasker
{
    /** Keys whose string value is fully redacted (never displayable). */
    private const REDACT = ['token', 'secret', 'password', 'credential', 'session', 'otp', 'recovery_code'];

    /** Keys masked as an email. */
    private const EMAIL = ['email', 'actor_label'];

    /** Keys masked as a phone/MSISDN. */
    private const PHONE = ['phone', 'msisdn'];

    /** Keys masked as a partial reference (payment/M-Pesa references). */
    private const REFERENCE = ['reference', 'mpesa_receipt', 'receipt_number', 'transaction_id'];

    /** Keys whose monetary value is policy-restricted (compensation). */
    private const RESTRICTED = ['salary', 'compensation', 'gross_pay', 'net_pay'];

    /**
     * Recursively mask an associative array of audit values.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function mask(array $values): array
    {
        $masked = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $masked[$key] = $this->mask($value);

                continue;
            }

            $masked[$key] = is_string($value) ? $this->maskValue((string) $key, $value) : $value;
        }

        return $masked;
    }

    /** Mask a single value by the semantics of its key. */
    public function maskValue(string $key, string $value): string
    {
        $k = strtolower($key);

        if ($this->matches($k, self::REDACT)) {
            return '[redacted]';
        }
        if ($this->matches($k, self::RESTRICTED)) {
            return '[restricted]';
        }
        if ($this->matches($k, self::EMAIL)) {
            return $value === '' ? $value : self::maskEmail($value);
        }
        if ($this->matches($k, self::PHONE)) {
            return self::maskPhone($value);
        }
        if ($this->matches($k, self::REFERENCE)) {
            return self::maskReference($value);
        }

        return $value;
    }

    /** j***@e***.com — enough to correlate, not to enumerate. */
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        $local = $parts[0] !== '' ? $parts[0][0].'***' : '***';

        if (count($parts) < 2) {
            return $local;
        }

        $domain = $parts[1];
        $dot = strrpos($domain, '.');
        $tld = $dot !== false ? substr($domain, $dot) : '';

        return $local.'@'.($domain !== '' ? $domain[0] : '').'***'.$tld;
    }

    /** Keep the last 3 digits only. */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) <= 3) {
            return '***';
        }

        return '***'.substr($digits, -3);
    }

    /** Keep the last 4 characters of a reference. */
    public static function maskReference(string $reference): string
    {
        if (strlen($reference) <= 4) {
            return '***';
        }

        return '***'.substr($reference, -4);
    }

    /** @param list<string> $needles */
    private function matches(string $key, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
