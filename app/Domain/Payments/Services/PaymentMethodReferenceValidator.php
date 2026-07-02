<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Exceptions\PaymentRecordingException;

/**
 * Method-specific evidence + reference validation (Plan §41; Phase 18A).
 *
 * Gate B: a component method must be concrete — `split_payment` is rejected.
 * cash: reference optional. mpesa_offline/bank_transfer/card_terminal/voucher/other:
 * a non-empty reference/evidence is required. mpesa_offline additionally validates
 * the normalized receipt format (uppercase alphanumeric, 8–15 chars). No Daraja/STK
 * integration — this is offline evidence only.
 */
final class PaymentMethodReferenceValidator
{
    public function validate(PaymentMethod $method, ?string $rawReference): void
    {
        if (! $method->isConcreteComponentMethod()) {
            throw PaymentRecordingException::invalidComponentMethod();
        }

        $present = $rawReference !== null && trim($rawReference) !== '';

        if ($method->requiresReference() && ! $present) {
            throw PaymentRecordingException::referenceRequired();
        }

        if ($method === PaymentMethod::MpesaOffline && $present) {
            $normalized = strtoupper((string) preg_replace('/\s+/', '', trim((string) $rawReference)));

            if (preg_match('/^[A-Z0-9]{8,15}$/', $normalized) !== 1) {
                throw PaymentRecordingException::invalidReferenceFormat();
            }
        }
    }
}
