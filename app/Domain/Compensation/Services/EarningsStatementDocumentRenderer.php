<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Enums\Currency;
use App\Support\MinimalPdf;
use App\Support\Money;

/**
 * Renders a personnel earnings-statement PDF byte string (Plan §63, §65; Phase 20H) using the
 * dependency-free {@see MinimalPdf} writer (no third-party renderer added; mirrors
 * ReceiptDocumentRenderer). Content comes from the FROZEN payout-item snapshot + its paid run — only
 * SAFE facts: merchant/branch/personnel public references + safe display names, statement period,
 * currency, bucketed integer-minor amounts, the payout-run ULID, and the paid date. It NEVER contains
 * an internal id, the raw external payment reference, a storage path, or a secret.
 */
final class EarningsStatementDocumentRenderer
{
    public function render(PersonnelPayoutItem $item): string
    {
        $item->loadMissing(['merchant', 'branch', 'staffProfile', 'payoutRun']);

        $run = $item->payoutRun;
        $branch = $item->branch;
        $staff = $item->staffProfile;

        $lines = [
            'EARNINGS STATEMENT',
            'Statement Ref: '.$item->ulid,
            'Payout Run: '.($run !== null ? $run->ulid : ''),
            'Personnel: '.($staff !== null ? (string) $staff->display_name : ''),
            'Personnel Ref: '.($staff !== null ? $staff->ulid : ''),
            'Branch: '.($branch !== null ? (string) $branch->name : ''),
            'Period: '.($run !== null ? $run->period_start->toDateString().' to '.$run->period_end->toDateString() : ''),
            'Currency: '.$item->currency,
            'Paid: '.($run !== null && $run->paid_at !== null ? $run->paid_at->toDateString() : ''),
            '',
            'Salary:     '.$this->format($item->salary_amount_minor, $item->currency),
            'Commission: '.$this->format($item->commission_amount_minor, $item->currency),
            'Adjustments:'.$this->format($item->adjustment_amount_minor, $item->currency),
            '',
            'Total Paid: '.$this->format($item->gross_amount_minor, $item->currency),
        ];

        return MinimalPdf::fromLines($lines, 'Earnings Statement '.$item->ulid);
    }

    private function format(int $minor, string $currency): string
    {
        return Money::ofMinor($minor, Currency::tryFrom($currency) ?? Currency::KES)->format();
    }
}
