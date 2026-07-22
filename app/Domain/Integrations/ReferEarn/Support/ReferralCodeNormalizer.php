<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Support;

use Illuminate\Support\Str;

/**
 * Deterministic normalization of a submitted referral code (Plan §58A.1, §13.17; Phase 21R-A).
 *
 * The raw submission is kept verbatim (encrypted) as evidence; this produces the single canonical
 * form Servana stores, indexes and sends to R&E. It is deliberately strict: R&E owns referral codes
 * as system of record, so Servana's only job is to recognise a well-formed one and to refuse to
 * forward anything else (Plan §58A.1: "Malformed codes are marked `invalid_format` and are never
 * sent to R&E").
 *
 * Normalization is: trim → strip surrounding quotes/zero-width characters → uppercase ASCII →
 * collapse internal whitespace away entirely (a pasted code that wrapped a line is still that code)
 * → match the configured pattern. Anything that fails the pattern normalizes to `null`, which is
 * exactly the `invalid_format` contract enforced by the database CHECK.
 */
final class ReferralCodeNormalizer
{
    public function normalize(?string $submitted): ?string
    {
        if ($submitted === null) {
            return null;
        }

        $maxLength = (int) config('refer-earn.capture.max_submitted_length', 64);

        // Bound the work before doing any of it: an oversized submission is invalid by definition
        // and must never become an expensive regex.
        if (mb_strlen($submitted) > $maxLength) {
            return null;
        }

        $candidate = trim($submitted);
        $candidate = trim($candidate, "\"'");
        // Zero-width and non-breaking characters survive copy/paste and would silently fail the
        // pattern; removing them makes a genuine code work instead of becoming invalid_format.
        $candidate = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $candidate);
        // Internal whitespace is never meaningful inside a code.
        $candidate = (string) preg_replace('/\s+/u', '', $candidate);
        $candidate = Str::upper($candidate);

        if ($candidate === '') {
            return null;
        }

        $pattern = (string) config('refer-earn.capture.code_pattern', '/^SERVANA-[A-Z0-9]{5,16}$/');

        return preg_match($pattern, $candidate) === 1 ? $candidate : null;
    }

    /** Convenience predicate mirroring `normalize() !== null`. */
    public function isWellFormed(?string $submitted): bool
    {
        return $this->normalize($submitted) !== null;
    }
}
