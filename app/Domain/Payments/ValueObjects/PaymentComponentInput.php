<?php

declare(strict_types=1);

namespace App\Domain\Payments\ValueObjects;

use App\Domain\Payments\Enums\PaymentMethod;
use Carbon\CarbonInterface;

/**
 * A single validated payment component the maker submitted (Plan §41; Phase 18A).
 * All authoritative fields (merchant/branch/invoice/currency/maker/status) are
 * derived server-side; this DTO carries only what the maker legitimately supplies.
 */
final readonly class PaymentComponentInput
{
    public function __construct(
        public PaymentMethod $method,
        public int $amountMinor,
        public ?string $rawReference,
        public CarbonInterface $paidAt,
        public ?int $payerClientId = null,
        // Optional per-component currency; when supplied it MUST equal the invoice
        // currency (a differing value is rejected as mixed_currency). Null inherits
        // the invoice currency. The maker never sets the authoritative stored value.
        public ?string $currency = null,
    ) {}
}
