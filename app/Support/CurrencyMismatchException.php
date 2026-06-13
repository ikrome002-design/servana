<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Currency;
use InvalidArgumentException;

/**
 * Thrown when an operation combines two Money values of different currencies.
 */
final class CurrencyMismatchException extends InvalidArgumentException
{
    public static function between(Currency $a, Currency $b): self
    {
        return new self(sprintf(
            'Cannot operate on mismatched currencies: %s and %s.',
            $a->value,
            $b->value,
        ));
    }
}
