<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

/**
 * A user's explicit theme choice (ADR-021; UI/UX plan §12.1–§12.2).
 *
 * The vocabulary is CLOSED at two values. There is deliberately no `System` / `Auto` case:
 * ADR-021 rule 2 forbids `prefers-color-scheme` from selecting the theme, so "follow the
 * operating system" must not be expressible anywhere in the stack.
 *
 * Absence of a value (a `null` column) means "no explicit choice", which resolves to
 * {@see self::Light}. That is why the column is nullable rather than defaulted.
 */
enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';

    /** The theme a user with no explicit choice receives (ADR-021 rule 2). */
    public static function default(): self
    {
        return self::Light;
    }

    /**
     * Resolve a stored value, failing safe to light.
     *
     * A malformed or removed value renders light rather than throwing, because a display
     * preference must never be able to break a bootstrap.
     */
    public static function resolve(?string $stored): self
    {
        return self::tryFrom((string) $stored) ?? self::default();
    }
}
