<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Support;

use App\Domain\Messaging\Sms\ValueObjects\SmsMessageMeasurement;

/**
 * Deterministic SMS character/segment arithmetic (Plan §64; Phase 21S).
 *
 * THE single authority for how many segments a body costs. The composer shows what this returns;
 * the preview and the confirm path both recompute it server-side, so a tampered client value can
 * never change what is billed.
 *
 * Standard GSM 03.38 / UCS-2 rules:
 *   - GSM-7 body: 160 characters in a single segment, 153 per segment once concatenated (the UDH
 *     that stitches parts together consumes 7 characters' worth of each segment).
 *   - Unicode (UCS-2) body: 70 characters single, 67 per concatenated segment.
 *   - A handful of GSM-7 characters ({ } [ ] ~ ^ \ | and the euro sign) are encoded as an escape
 *     plus the character, so they count as TWO characters.
 *
 * An empty body measures as zero characters and zero segments; the Form Request rejects it long
 * before this is reached, and the DB CHECK requires >= 1 of each on a persisted campaign.
 */
final class SmsMessageSegmentCalculator
{
    public const GSM_SINGLE_LIMIT = 160;

    public const GSM_CONCATENATED_LIMIT = 153;

    public const UNICODE_SINGLE_LIMIT = 70;

    public const UNICODE_CONCATENATED_LIMIT = 67;

    /**
     * The GSM 03.38 basic character set plus its extension table. Anything outside this forces the
     * whole message to UCS-2.
     */
    // Single-quoted segments so `$` is a GSM CHARACTER, not a PHP variable; the two control
    // characters are the only double-quoted pieces.
    private const GSM_BASIC = '@£$¥èéùìòÇ'."\n".'Øø'."\r"
        .'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
        .'¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /** Characters that occupy two GSM-7 positions (escape + character). */
    private const GSM_EXTENDED = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];

    public function measure(string $body): SmsMessageMeasurement
    {
        $requiresUnicode = ! $this->isGsmEncodable($body);
        $characterCount = $requiresUnicode
            ? mb_strlen($body, 'UTF-8')
            : $this->gsmLength($body);

        if ($characterCount === 0) {
            return new SmsMessageMeasurement(0, 0, $requiresUnicode, 0);
        }

        $singleLimit = $requiresUnicode ? self::UNICODE_SINGLE_LIMIT : self::GSM_SINGLE_LIMIT;
        $concatenatedLimit = $requiresUnicode ? self::UNICODE_CONCATENATED_LIMIT : self::GSM_CONCATENATED_LIMIT;

        if ($characterCount <= $singleLimit) {
            return new SmsMessageMeasurement(
                $characterCount,
                1,
                $requiresUnicode,
                $singleLimit - $characterCount,
            );
        }

        $segments = (int) ceil($characterCount / $concatenatedLimit);

        return new SmsMessageMeasurement(
            $characterCount,
            $segments,
            $requiresUnicode,
            ($segments * $concatenatedLimit) - $characterCount,
        );
    }

    /** Convenience: segment count only. */
    public function segmentsFor(string $body): int
    {
        return $this->measure($body)->segmentCount;
    }

    /** Whether every character of the body is representable in GSM 03.38. */
    public function isGsmEncodable(string $body): bool
    {
        $length = mb_strlen($body, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($body, $i, 1, 'UTF-8');

            if (in_array($char, self::GSM_EXTENDED, true)) {
                continue;
            }

            if (! str_contains(self::GSM_BASIC, $char)) {
                return false;
            }
        }

        return true;
    }

    /** GSM-7 length, counting extension-table characters twice. */
    private function gsmLength(string $body): int
    {
        $length = mb_strlen($body, 'UTF-8');
        $count = 0;

        for ($i = 0; $i < $length; $i++) {
            $count += in_array(mb_substr($body, $i, 1, 'UTF-8'), self::GSM_EXTENDED, true) ? 2 : 1;
        }

        return $count;
    }
}
