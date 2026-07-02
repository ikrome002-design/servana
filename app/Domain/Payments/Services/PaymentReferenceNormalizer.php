<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\PaymentMethod;

/**
 * Normalizes payment references (Plan §41; Phase 18A). The normalized form is the
 * $hidden comparison key used for duplicate detection: trimmed, internal whitespace
 * removed, uppercased. The display form is the trimmed original, stored encrypted
 * and only ever surfaced as a masked suffix. Cash / empty references normalize to
 * null (no duplicate check).
 */
final class PaymentReferenceNormalizer
{
    public function normalize(PaymentMethod $method, ?string $raw): ?string
    {
        if (! $method->requiresReference() || $raw === null) {
            return $this->clean($raw) === null ? null : ($method->requiresReference() ? $this->key($raw) : null);
        }

        return $this->key($raw);
    }

    /** The trimmed original for encrypted display storage (masked suffix at read). */
    public function display(?string $raw): ?string
    {
        return $this->clean($raw);
    }

    private function key(?string $raw): ?string
    {
        $clean = $this->clean($raw);

        if ($clean === null) {
            return null;
        }

        return strtoupper((string) preg_replace('/\s+/', '', $clean));
    }

    private function clean(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
