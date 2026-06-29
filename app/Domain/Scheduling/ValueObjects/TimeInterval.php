<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\ValueObjects;

use InvalidArgumentException;

/**
 * A half-open within-day interval [start, end) in branch business time
 * (Phase 15B). Times are seconds-from-midnight (0…86400). A cross-midnight or
 * zero-length interval is rejected — those are the same invariants the DB CHECK
 * `start_time < end_time` enforces, surfaced earlier as a typed error.
 *
 * Immutable.
 */
final class TimeInterval
{
    public function __construct(
        public readonly int $startSeconds,
        public readonly int $endSeconds,
    ) {
        if ($startSeconds < 0 || $endSeconds > 86400) {
            throw new InvalidArgumentException('Time interval is outside a single day.');
        }
        if ($startSeconds >= $endSeconds) {
            throw new InvalidArgumentException('Interval end must be after its start (no zero-length or cross-midnight interval).');
        }
    }

    /** Parse "HH:MM" or "HH:MM:SS" (24h) into seconds-from-midnight. */
    public static function secondsFromString(string $time): int
    {
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $time, $m) !== 1) {
            throw new InvalidArgumentException("Invalid time format: {$time}");
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];
        $seconds = isset($m[3]) ? (int) $m[3] : 0;

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            throw new InvalidArgumentException("Invalid time value: {$time}");
        }

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    public static function fromStrings(string $start, string $end): self
    {
        return new self(self::secondsFromString($start), self::secondsFromString($end));
    }

    /** Canonical "HH:MM:SS" for the given seconds-from-midnight. */
    public static function secondsToString(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** Half-open membership: start <= second < end. */
    public function contains(int $second): bool
    {
        return $second >= $this->startSeconds && $second < $this->endSeconds;
    }

    /** Half-open overlap: the two intervals share at least one point. */
    public function overlaps(self $other): bool
    {
        return $this->startSeconds < $other->endSeconds && $other->startSeconds < $this->endSeconds;
    }

    public function startString(): string
    {
        return self::secondsToString($this->startSeconds);
    }

    public function endString(): string
    {
        return self::secondsToString($this->endSeconds);
    }
}
