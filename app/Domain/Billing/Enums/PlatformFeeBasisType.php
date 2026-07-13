<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Percentage platform-fee basis (Scope §6.3.2; Plan §51; Phase 20E). The single
 * CHECK-enforced vocabulary that decides which server-owned merchant-client invoice amount
 * the percentage rate is applied to. No aliases (`service_price`, `invoice_item_total`,
 * `invoice_subtotal` are forbidden). Mirrors the PostgreSQL CHECKs on
 * `platform_fee_configurations.fee_basis_type` and
 * `platform_fee_ledger_entries.fee_basis_type`. Parity guarded by `Phase20EEnumParityTest`.
 */
enum PlatformFeeBasisType: string
{
    case MerchantClientInvoiceServiceSubtotal = 'merchant_client_invoice_service_subtotal';
    case MerchantClientInvoiceTotal = 'merchant_client_invoice_total';
    case NetAfterDiscount = 'net_after_discount';
    case InvoiceItemSubtotal = 'invoice_item_subtotal';
    case ValidatedPaidAmount = 'validated_paid_amount';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $b): string => $b->value, self::cases());
    }

    /** Item-level bases drive per-item largest-remainder provenance. */
    public function isItemLevel(): bool
    {
        return $this === self::InvoiceItemSubtotal;
    }

    /** True when the basis amount is only known at (per-)validation, not finalization. */
    public function isValidationLevel(): bool
    {
        return $this === self::ValidatedPaidAmount;
    }

    public function label(): string
    {
        return match ($this) {
            self::MerchantClientInvoiceServiceSubtotal => 'Merchant-client invoice service subtotal',
            self::MerchantClientInvoiceTotal => 'Merchant-client invoice total',
            self::NetAfterDiscount => 'Net after discount',
            self::InvoiceItemSubtotal => 'Invoice item subtotal',
            self::ValidatedPaidAmount => 'Validated paid amount',
        };
    }
}
