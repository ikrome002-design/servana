<?php

declare(strict_types=1);

namespace App\Domain\Receipts\Services;

use App\Domain\Receipts\Models\Receipt;
use App\Enums\Currency;
use App\Support\MinimalPdf;
use App\Support\Money;

/**
 * Renders a receipt PDF byte string (Plan §43; Gate J; Phase 18B) using the
 * dependency-free {@see MinimalPdf} writer (no third-party renderer added). The
 * document contains only SAFE data — receipt/invoice ULIDs + numbers, merchant/branch
 * identity, validated total, currency, per-component method + integer-minor amounts,
 * and the issued timestamp. It NEVER contains a full/normalized reference, internal
 * id, storage path, or secret.
 */
final class ReceiptDocumentRenderer
{
    public function render(Receipt $receipt): string
    {
        $receipt->loadMissing(['merchant', 'branch', 'invoice']);

        $invoice = $receipt->invoice;
        $branch = $receipt->branch;

        $lines = [
            'RECEIPT',
            'Receipt No: '.$receipt->receipt_number,
            'Receipt Ref: '.$receipt->ulid,
            'Invoice: '.($invoice !== null ? (string) ($invoice->invoice_number ?? $invoice->ulid) : ''),
            'Branch: '.($branch !== null ? (string) $branch->name : ''),
            'Issued: '.$receipt->created_at?->toDateTimeString(),
            '',
            'Amount: '.$this->format($receipt->amount_minor, $receipt->currency),
            '',
            'Components:',
        ];

        foreach ($receipt->components as $component) {
            $method = (string) $component['method'];
            $amount = (int) $component['amount_minor'];
            $lines[] = '  - '.$method.': '.$this->format($amount, $receipt->currency);
        }

        if ($receipt->isReissue()) {
            $lines[] = '';
            $lines[] = 'Reissue of receipt id (internal reference withheld).';
        }

        return MinimalPdf::fromLines($lines, 'Receipt '.$receipt->receipt_number);
    }

    private function format(int $minor, string $currency): string
    {
        $money = Money::ofMinor($minor, Currency::tryFrom($currency) ?? Currency::KES);

        return $money->format();
    }
}
