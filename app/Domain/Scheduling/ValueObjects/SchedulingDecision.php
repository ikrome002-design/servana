<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\ValueObjects;

/**
 * Immutable result of PersonnelSchedulingValidator (Plan §80 Phase 15B).
 *
 * `code` is a stable, safe error code (defined in the data dictionary) — it never
 * carries internal database ids or cross-tenant existence information. `message`
 * is a generic human-readable reason safe to surface to an authorized actor.
 */
final class SchedulingDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $code,
        public readonly ?string $message,
    ) {}

    public static function pass(): self
    {
        return new self(true, null, null);
    }

    public static function deny(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
