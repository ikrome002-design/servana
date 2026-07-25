<?php

declare(strict_types=1);

namespace App\Domain\Search\Support;

use App\Domain\Clients\Support\PhoneNumberNormalizer;

/**
 * Decides whether a search term is a COMPLETE phone number (Phase 22;
 * `docs/architecture/search/search-security.md` §4).
 *
 * This class exists because {@see PhoneNumberNormalizer::normalize()}
 * is deliberately permissive — it canonicalizes *any* digit string, so `1024` (a plausible receipt
 * number) would normalize to `+1024`. Search must not treat that as a phone number, for two
 * reasons:
 *
 *   1. A partial-phone search would be an enumeration oracle: an attacker could confirm digit by
 *      digit whether a number belongs to a client of this branch (Plan §73 "personnel contact
 *      extraction"; ADR-010).
 *   2. Short numeric terms are legitimately invoice and receipt numbers, and must reach the normal
 *      text path.
 *
 * So the rule is strict: only a *whole* number in one of the accepted shapes is phone-like. Anything
 * shorter, anything containing a letter, and anything ambiguous is NOT — and because no phone digit
 * is indexed anywhere, a partial fragment cannot match a phone through the text path either.
 */
final class SearchPhoneCandidate
{
    /**
     * Accepted complete forms:
     *   +254 7XXXXXXXX / +254 1XXXXXXXX   (with or without the `+`, with or without separators)
     *   07XXXXXXXX / 01XXXXXXXX          (10 digits, Kenyan local)
     *   7XXXXXXXX / 1XXXXXXXX            (9 digits, Kenyan national significant number)
     *   +<country><number>               (explicit international, 10–15 digits total)
     */
    public static function isPhoneLike(string $term): bool
    {
        $trimmed = trim($term);

        if ($trimmed === '') {
            return false;
        }

        // A phone number contains no letters. This is what keeps "Jane 0712" out of the phone path.
        if (preg_match('/[^\d\s+()\-.]/', $trimmed) === 1) {
            return false;
        }

        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        // Explicit international form: trust the `+`, but still require a plausible E.164 length.
        if ($hasPlus) {
            return strlen($digits) >= 10 && strlen($digits) <= 15;
        }

        // Kenyan +254 form written without the plus.
        if (preg_match('/^254[17]\d{8}$/', $digits) === 1) {
            return true;
        }

        // Kenyan local form: 07XXXXXXXX / 01XXXXXXXX.
        if (preg_match('/^0[17]\d{8}$/', $digits) === 1) {
            return true;
        }

        // Kenyan national significant number: 7XXXXXXXX / 1XXXXXXXX.
        return preg_match('/^[17]\d{8}$/', $digits) === 1;
    }
}
