<?php

declare(strict_types=1);

namespace App\Domain\Clients\Support;

use InvalidArgumentException;

/**
 * Normalizes client phone numbers to a single canonical form before encryption,
 * blind indexing, and duplicate detection (Plan §35; Phase 15A).
 *
 * Kenyan-first (Africa/Nairobi business context): accepts `07XXXXXXXX`,
 * `01XXXXXXXX`, `+2547XXXXXXXX`, `2547XXXXXXXX`, `7XXXXXXXX` and produces a
 * canonical `+2547XXXXXXXX` / `+2541XXXXXXXX` (E.164). Other already-E.164 inputs
 * (`+<country><number>`) are preserved digit-normalized so the platform is not
 * Kenya-only. Normalization is deterministic so the same human number always maps
 * to the same blind index and the same duplicate bucket.
 */
final class PhoneNumberNormalizer
{
    /** Canonicalize a phone number; throws on input with no usable digits. */
    public static function normalize(string $raw): string
    {
        $hasPlus = str_starts_with(ltrim($raw), '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('Phone number contains no digits.');
        }

        // Explicit international form (leading +) — keep as E.164 digits.
        if ($hasPlus) {
            return '+'.$digits;
        }

        // Kenyan local/national forms → +254 national significant number.
        if (str_starts_with($digits, '254')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+254'.substr($digits, 1);
        }

        if (strlen($digits) === 9 && (str_starts_with($digits, '7') || str_starts_with($digits, '1'))) {
            return '+254'.$digits;
        }

        // Fallback: treat as already-international digits (no country assumption).
        return '+'.$digits;
    }

    /** Last four digits of the normalized number, for masked display. */
    public static function lastFour(string $normalized): string
    {
        $digits = preg_replace('/\D+/', '', $normalized) ?? '';

        return substr(str_pad($digits, 4, '0', STR_PAD_LEFT), -4);
    }
}
