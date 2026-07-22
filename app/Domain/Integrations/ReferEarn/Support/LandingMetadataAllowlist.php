<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Support;

/**
 * Allowlist filter for `referral_snapshots.landing_metadata` (Plan §13.17 "utm-style minimal, no
 * PII, allowlisted keys only"; §9 rule 23; §74; Phase 21R-A).
 *
 * This is an ALLOWLIST, not a denylist, and that distinction is the control: a key that is not
 * named in `config('refer-earn.capture.landing_metadata_allowlist')` is dropped, so names, emails,
 * phone numbers, IP addresses, user agents, free text, raw headers, cookies, session ids and
 * unknown tracking identifiers cannot be stored even if a caller passes them — there is no key for
 * them to land in. Plan §24.5 additionally forbids logging raw landing metadata.
 *
 * Values are coerced to strings, trimmed, control-character-stripped and length-bounded, so an
 * attacker-supplied query string cannot inflate the row or smuggle newlines into anything that
 * later reads it.
 */
final class LandingMetadataAllowlist
{
    /**
     * @param  array<array-key, mixed>  $candidate
     * @return array<string, string>|null null when nothing survives (column stays NULL)
     */
    public function filter(array $candidate): ?array
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('refer-earn.capture.landing_metadata_allowlist', []);
        $maxLength = (int) config('refer-earn.capture.landing_metadata_max_value_length', 128);

        $filtered = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $candidate)) {
                continue;
            }

            $value = $candidate[$key];

            // Only flat scalars are meaningful here; arrays/objects are a smuggling vector.
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $value = trim((string) $value);
            $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

            if ($value === '') {
                continue;
            }

            $filtered[$key] = mb_substr($value, 0, $maxLength);
        }

        // Keys are sorted so the stored JSON is stable regardless of submission order — the same
        // capture always produces the same row, which keeps evidence comparisons meaningful.
        ksort($filtered);

        return $filtered === [] ? null : $filtered;
    }

    /** @return list<string> */
    public function allowedKeys(): array
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('refer-earn.capture.landing_metadata_allowlist', []);

        return $allowed;
    }
}
