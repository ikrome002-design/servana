<?php

declare(strict_types=1);

namespace App\Domain\Files\Enums;

use App\Domain\Files\FilePurposeRegistry;

/**
 * File purposes (Plan §13.13, §65; Phase 10F). The DB CHECK on
 * `uploaded_files.purpose` mirrors these values exactly. Per-purpose rules
 * (uploadable vs generated-only, limits, permissions) live in
 * {@see FilePurposeRegistry} — the single registry.
 */
enum FilePurpose: string
{
    case MerchantLogo = 'merchant_logo';
    case ProfilePhoto = 'profile_photo';
    case DisputeEvidence = 'dispute_evidence';
    case AuditEvidence = 'audit_evidence';
    case FinanceExport = 'finance_export';
    case AuditExport = 'audit_export';
    case InvoicePdf = 'invoice_pdf';
    case ReceiptPdf = 'receipt_pdf';
    case BillingInvoicePdf = 'billing_invoice_pdf';
    case EarningsStatement = 'earnings_statement';
    case DayCloseReport = 'day_close_report';
    case CashUpReport = 'cash_up_report';
}
