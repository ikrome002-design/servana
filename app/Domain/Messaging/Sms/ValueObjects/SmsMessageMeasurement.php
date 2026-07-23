<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\ValueObjects;

use App\Domain\Messaging\Sms\Support\SmsMessageSegmentCalculator;

/**
 * The measured shape of one SMS message body (Plan §64 "compose within configurable char/segment
 * limit"; Phase 21S). Produced only by {@see SmsMessageSegmentCalculator} — the frontend displays
 * this, it never computes an authoritative value of its own.
 */
final readonly class SmsMessageMeasurement
{
    public function __construct(
        public int $characterCount,
        public int $segmentCount,
        /** True when the body contains a character outside the GSM 03.38 basic set. */
        public bool $requiresUnicode,
        /** Characters still available before the next segment starts. */
        public int $charactersRemainingInSegment,
    ) {}
}
