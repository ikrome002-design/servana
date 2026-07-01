<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Clients\Models\Client;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Invoicing\Concerns\BuildsInvoiceAudit;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceDraftComposer;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a DRAFT merchant-client invoice from one or more completed service
 * sessions (Plan §40; Phase 17; (none) → draft). Front Office only. No invoice
 * number is allocated and no snapshot is claimed yet — finalization
 * ({@see FinalizeInvoice}) does that. Each line's price, personnel, and
 * preferred-personnel fee are DERIVED from the locked completed session/service via
 * {@see InvoiceDraftComposer}, never accepted from the browser. The draft is
 * editable; removing items is allowed only before finalization.
 *
 * Validates (Gate A): every source session is `completed`, belongs to the same
 * merchant/branch/client, shares one currency, and is not already on another
 * invoice. Period-lock enforced (Gate C). Any failure rolls back with no draft and
 * no success audit.
 */
final class CreateInvoiceDraft
{
    use BuildsInvoiceAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly InvoiceDraftComposer $composer,
        private readonly FinancialPeriodGuard $periodGuard,
    ) {}

    /**
     * @param  list<ServiceSession>  $sessions
     */
    public function handle(Client $client, array $sessions, User $actor): Invoice
    {
        $this->periodGuard->ensureOpen($client->merchant_id, $client->branch_id);

        return DB::transaction(function () use ($client, $sessions, $actor): Invoice {
            $invoice = new Invoice([
                'merchant_id' => $client->merchant_id,
                'branch_id' => $client->branch_id,
                'client_id' => $client->id,
                'status' => InvoiceStatus::Draft,
                'currency' => 'KES',
                'subtotal_minor' => 0,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 0,
                'validated_paid_minor' => 0,
                'created_by' => $actor->id,
            ]);
            $invoice->save();

            $this->composer->compose($invoice, $client, $sessions);

            $invoice->load(['client', 'items']);

            $this->audit->record(
                AuditEvent::InvoiceCreated,
                $actor,
                $invoice->merchant_id,
                $invoice->branch_id,
                $invoice,
                $this->invoiceAuditContext($invoice, [
                    'item_count' => $invoice->items->count(),
                    'new_state' => InvoiceStatus::Draft->value,
                ]),
            );

            return $invoice;
        });
    }
}
