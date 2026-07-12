<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Support\MinimalPdf;

/**
 * Render a subscription invoice to PDF bytes (Plan §49, §65; Phase 20B) using the dependency-free
 * {@see MinimalPdf} writer — no third-party renderer added to the pinned stack (matching the Phase 18B
 * receipt renderer).
 *
 * The **pending payment-reference placeholder** is rendered verbatim while the invoice is not yet
 * registered with Wallet (Phase 20B ships every invoice `unregistered` with a null `account_reference`).
 * No fabricated account reference, internal ID, Wallet payment ID, or storage path is ever rendered.
 * Registered-path rendering (a real `SRV-PAY-…` reference) is Phase 20D-W.
 */
final class SubscriptionInvoiceDocumentRenderer
{
    public const PENDING_REFERENCE_TEXT = 'Payment reference pending — see your billing dashboard';

    public function render(SubscriptionInvoice $invoice): string
    {
        $invoice->loadMissing(['merchant', 'plan', 'price', 'items']);
        $merchant = $invoice->merchant;
        $plan = $invoice->plan;
        $price = $invoice->price;

        $lines = [
            'Servana by Citrus — Subscription Invoice',
            '',
            'Invoice No: '.((string) ($invoice->invoice_number ?? $invoice->ulid)),
            'Invoice Ref: '.$invoice->ulid,
            'Merchant: '.($merchant !== null ? (string) $merchant->name : ''),
            'Plan: '.($plan !== null ? (string) $plan->name : ''),
            'Billing interval: '.($price !== null ? $price->billing_interval->label() : ''),
            'Period: '.$invoice->period_start->toDateString().' to '.$invoice->period_end->toDateString(),
            'Issued: '.($invoice->issued_at?->toDateString() ?? ''),
            'Due: '.($invoice->due_at?->toDateString() ?? ''),
            'Status: '.$invoice->status->label(),
            '',
            'Line items:',
        ];

        foreach ($invoice->items as $item) {
            $lines[] = '  - '.$item->description.': '.$this->money($item->amount_minor, $invoice->currency);
        }

        $lines[] = '';
        $lines[] = 'Subtotal: '.$this->money($invoice->subtotal_minor, $invoice->currency);
        $lines[] = 'Discount: '.$this->money($invoice->discount_minor, $invoice->currency);
        $lines[] = 'Total: '.$this->money($invoice->total_minor, $invoice->currency);
        $lines[] = 'Balance: '.$this->money($invoice->balance_minor, $invoice->currency);
        $lines[] = '';

        // Wallet payment reference — pending until Phase 20D-W registration (ADR-014).
        $lines[] = $invoice->hasWalletReference()
            ? 'Payment reference: '.((string) $invoice->account_reference)
            : self::PENDING_REFERENCE_TEXT;

        return MinimalPdf::fromLines($lines, 'Invoice '.((string) ($invoice->invoice_number ?? $invoice->ulid)));
    }

    private function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format($minor / 100, 2);
    }
}
