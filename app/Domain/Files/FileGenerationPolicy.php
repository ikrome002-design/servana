<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Files\Enums\FilePurpose;

/**
 * Billing-read-only generation seam (Plan §65; Phase 10F).
 *
 * The billing state machine does not exist yet (Phases 20A/20B). This is the
 * tested seam the owning billing phase attaches its real read-only state to: when a
 * merchant's billing access is read-only, generating a NEW billing-gated file
 * (exports/PDFs/reports/statements) is denied — but an already-available authorized
 * file stays downloadable (the download path never consults this policy).
 *
 * No billing/subscription/finance_exports tables are created here.
 */
final class FileGenerationPolicy
{
    public function canGenerate(FilePurpose $purpose, bool $billingReadOnly): bool
    {
        if ($billingReadOnly && FilePurposeRegistry::for($purpose)->billingReadOnlyGeneration) {
            return false;
        }

        return true;
    }

    public function assertCanGenerate(FilePurpose $purpose, bool $billingReadOnly): void
    {
        if (! $this->canGenerate($purpose, $billingReadOnly)) {
            throw new \DomainException(
                "Cannot generate a new {$purpose->value} while billing access is read-only."
            );
        }
    }
}
