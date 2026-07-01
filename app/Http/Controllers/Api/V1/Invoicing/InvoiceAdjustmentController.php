<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Invoicing;

use App\Domain\Invoicing\Actions\AdjustInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoicing\InvoiceReasonRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\User;

/**
 * Finance invoice adjustment (Plan §25.3, §40; Phase 17; Gate B). Finance only
 * (`invoice.adjustment.manage`; MFA enforced at the route boundary). The adjustment is
 * additive and non-destructive (original snapshots + number untouched); the
 * transactional action records actor/time/reason and emits the `invoice.adjusted`
 * audit event. Period-lock enforced in the action.
 */
final class InvoiceAdjustmentController extends Controller
{
    private const RELATIONS = ['client', 'items', 'items.service', 'items.personnel', 'items.serviceSession'];

    public function store(InvoiceReasonRequest $request, Invoice $invoice, AdjustInvoice $action): InvoiceResource
    {
        $this->authorize('adjust', $invoice);

        /** @var User $actor */
        $actor = $request->user();

        return InvoiceResource::make(
            $action->handle($invoice, $actor, (string) $request->validated('reason'))->load(self::RELATIONS),
        );
    }
}
