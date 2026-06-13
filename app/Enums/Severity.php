<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Severity for audit events and structured logs (Plan §22.2). Backed by string
 * so it maps directly to a DB enum/CHECK in later phases.
 */
enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /** Monolog level name this severity maps to for operational logs. */
    public function logLevel(): string
    {
        return match ($this) {
            self::Low => 'info',
            self::Medium => 'notice',
            self::High => 'warning',
            self::Critical => 'critical',
        };
    }
}
