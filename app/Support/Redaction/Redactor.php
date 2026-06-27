<?php

declare(strict_types=1);

namespace App\Support\Redaction;

/**
 * Masks sensitive data before it reaches any log sink (CLAUDE.md §6.4,
 * Plan §3 rule 6, §22.1). Two strategies:
 *   1. Key-based — any array key whose name matches a sensitive token has its
 *      whole value replaced (covers token, magic_link, password, etc.).
 *   2. Pattern-based — emails and phone numbers are masked wherever they appear
 *      in string values, regardless of key.
 *
 * Magic Link tokens must never be logged (CLAUDE.md §6.9); the `token` /
 * `magic_link` key rules guarantee that.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    /** Substrings that mark an array key's value as sensitive (case-insensitive). */
    private const SENSITIVE_KEYS = [
        'token',
        'magic_link',
        'magiclink',
        'password',
        'authorization',
        'api_key',
        'apikey',
        'secret',
        'payment_reference',
        // File domain (Plan §24.5, §65, §73; Phase 10F): never log signed-URL
        // signatures, storage paths, the content hash, the original filename, or
        // any scanner/malware payload.
        'signature',
        'sha256',
        'quarantine_path',
        'final_path',
        'storage_disk',
        'original_filename',
        'malware_payload',
        'scanner_response',
    ];

    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    // Optional +, then 9-15 digits possibly separated by spaces or hyphens.
    private const PHONE_PATTERN = '/\+?\d[\d\s\-]{7,13}\d/';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = $this->redactValue($value);
        }

        return $result;
    }

    public function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redactArray($value);
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        return $value;
    }

    /** Mask emails and phone numbers inside a free-form string (e.g. a log message). */
    public function redactString(string $value): string
    {
        $value = (string) preg_replace(self::EMAIL_PATTERN, self::REDACTED, $value);
        $value = (string) preg_replace(self::PHONE_PATTERN, self::REDACTED, $value);

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($needle, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
