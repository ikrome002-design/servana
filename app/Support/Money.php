<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Currency;
use RangeException;

/**
 * Immutable money value object (CLAUDE.md §6.6, Plan AS-3): amounts are stored
 * as integer minor units (e.g. cents) only — never floats. All arithmetic is
 * integer-safe and currency-checked; even formatting avoids floating point.
 */
final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public Currency $currency = Currency::KES,
    ) {}

    /** Construct from integer minor units (cents). Floats are rejected by the type system. */
    public static function ofMinor(int $minorUnits, Currency $currency = Currency::KES): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(Currency $currency = Currency::KES): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::guardInt($this->minorUnits + $other->minorUnits), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::guardInt($this->minorUnits - $other->minorUnits), $this->currency);
    }

    /** Scale by an integer factor (e.g. quantity). Non-integer factors are rejected by the type system. */
    public function multiply(int $factor): self
    {
        return new self(self::guardInt($this->minorUnits * $factor), $this->currency);
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    public function equals(self $other): bool
    {
        return $this->isSameCurrency($other) && $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->greaterThan($other) || $this->equals($other);
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->lessThan($other) || $this->equals($other);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    /** Human-readable amount, e.g. "KES 1,535.00". Computed with integers only. */
    public function format(): string
    {
        $scale = $this->currency->minorUnitScale();
        $abs = abs($this->minorUnits);
        $major = intdiv($abs, $scale);
        $fraction = $abs % $scale;

        $formatted = $this->currency->symbol().' '
            .($this->minorUnits < 0 ? '-' : '')
            .number_format($major)
            .'.'
            .str_pad((string) $fraction, $this->currency->fractionDigits(), '0', STR_PAD_LEFT);

        return $formatted;
    }

    /**
     * API representation (Plan §11.4: money as { amount, currency, formatted }).
     *
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->minorUnits,
            'currency' => $this->currency->value,
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->isSameCurrency($other)) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }

    /**
     * Detect 64-bit integer overflow: PHP silently promotes overflowing integer
     * arithmetic to float, which would break the integer-only invariant.
     */
    private static function guardInt(int|float $value): int
    {
        if (! is_int($value)) {
            throw new RangeException('Money arithmetic overflowed the integer range.');
        }

        return $value;
    }
}
