<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Services\SubscriptionInvoiceDocumentRenderer;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\FileGenerationPolicy;
use App\Domain\Files\Services\GeneratedFileWriter;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Generate (or regenerate) a subscription invoice's PDF into the Phase 10F private-file domain
 * (Plan §49, §65; Phase 20B). No new file table, storage service, public path, object-store URL, or
 * Wallet seam is created — it reuses `GeneratedFileWriter` (purpose `billing_invoice_pdf`) exactly
 * like the Phase 18B receipt PDF.
 *
 * **Billing-status generation gate (§22):** new generation is blocked in `read_only_grace` /
 * `suspended_billing` via {@see FileGenerationPolicy} driven by {@see Merchant::billingBlocksMutations()}
 * (which reads `merchants.billing_status` only, never the subscription record) → 403 `billing_read_only`.
 * `trialing`/`active`/`overdue` allow generation. Existing PDFs stay downloadable in read-only states
 * (the download path never consults this gate).
 *
 * Versioned: each regeneration writes a new `uploaded_files` version, revokes the prior one, updates
 * `file_id` + increments `pdf_version`, and emits exactly one `subscription_invoice.pdf_generated`
 * event. The issued financial snapshot is untouched (`file_id`/`pdf_version` are not financial fields).
 * Requires an active tenant context.
 */
final class GenerateSubscriptionInvoicePdf
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SubscriptionInvoiceDocumentRenderer $renderer,
        private readonly GeneratedFileWriter $writer,
        private readonly FileGenerationPolicy $generationPolicy,
    ) {}

    public function handle(SubscriptionInvoice $invoice, ?User $actor = null): SubscriptionInvoice
    {
        $merchant = Merchant::query()->whereKey($invoice->merchant_id)->firstOrFail();

        // Billing-status generation gate — blocked in read_only_grace / suspended_billing.
        if (! $this->generationPolicy->canGenerate(FilePurpose::BillingInvoicePdf, $merchant->billingBlocksMutations())) {
            throw TenantAccessException::billingReadOnly();
        }

        $bytes = $this->renderer->render($invoice);
        $number = (string) ($invoice->invoice_number ?? $invoice->ulid);
        $merchantUlid = $merchant->ulid;

        $file = $this->writer->write(
            FilePurpose::BillingInvoicePdf,
            $bytes,
            'subscription-invoice-'.$number.'.pdf',
            'application/pdf',
            'pdf',
            $invoice->merchant_id,
            null,
            null,
        );

        return DB::transaction(function () use ($invoice, $file, $actor, $merchantUlid): SubscriptionInvoice {
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            // Revoke the previous version so only the current PDF is downloadable.
            if ($locked->file_id !== null && $locked->file_id !== $file->id) {
                $previous = $locked->file()->first();
                $previous?->markLifecycle(FileLifecycleStatus::Revoked);
            }

            $locked->file_id = $file->id;
            $locked->pdf_version = $locked->pdf_version + 1;
            $locked->save();

            $this->audit->record(AuditEvent::SubscriptionInvoicePdfGenerated, $actor, $locked->merchant_id, null, $locked, [
                'invoice_id' => $locked->ulid,
                'file_id' => $file->ulid,
                'merchant_id' => $merchantUlid,
                'version' => $locked->pdf_version,
            ]);

            return $locked;
        });
    }
}
