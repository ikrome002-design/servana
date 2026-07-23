<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Support;

use App\Domain\Clients\Support\PhoneNumberNormalizer;

/**
 * THE only way a phone number becomes displayable anywhere in the SMS surface (ADR-010, Plan §64,
 * §74; Phase 21S).
 *
 * Every Resource, screen spec and test in this phase renders through {@see mask()} or
 * {@see maskFromLastFour()}. Neither can ever return more than four digits — {@see mask()} takes a
 * full number only so it can derive the last four and then discards the rest, and the result is
 * built from the four digits alone, never by trimming the original string.
 *
 * There is deliberately no `unmask()`, no `full()` and no formatter that widens the output.
 */
final class PhoneNumberDisplayMasker
{
    private const MASK_PREFIX = '••• ••• ';

    /** Mask a full/normalized number for display. The full value is never part of the result. */
    public static function mask(string $phone): string
    {
        return self::maskFromLastFour(PhoneNumberNormalizer::lastFour($phone));
    }

    /**
     * Mask from a stored `phone_last_four` — the normal path, because the SMS surfaces read the
     * masked column and never decrypt the delivery snapshot.
     */
    public static function maskFromLastFour(string $lastFour): string
    {
        $digits = preg_replace('/\D+/', '', $lastFour) ?? '';

        return self::MASK_PREFIX.substr(str_pad($digits, 4, '0', STR_PAD_LEFT), -4);
    }
}
