<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Invoicing;

use App\Domain\Invoicing\Actions\ExecuteInvoiceVoid;
use App\Domain\Invoicing\Actions\RejectInvoiceVoid;
use App\Domain\Invoicing\Actions\RequestInvoiceVoid;
use App\Domain\Invoicing\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoicing\InvoiceReasonRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Finance invoice void workflow (Plan §25.3, §40; Phase 17). Finance only
 * (`invoice.void.request_or_execute_as_policy`; MFA + fresh step-up enforced at the
 * route boundary). Request → void_pending (mandatory reason); execute → voided;
 * reject → restored prior payable state. Each step delegates to a transactional,
 * additive, non-destructive domain action. Period-lock enforced in the action.
 */
final class InvoiceVoidController extends Controller
{
    private const RELATIONS = ['client', 'items', 'items.service', 'items.personnel', 'items.serviceSession'];

    public function request(InvoiceReasonRequest $request, Invoice $invoice, RequestInvoiceVoid $action): InvoiceResource
    {
        $this->authorize('void', $invoice);

        /** @var User $actor */
        $actor = $request->user();

        return InvoiceResource::make(
            $action->handle($invoice, $actor, (string) $request->validated('reason'))->load(self::RELATIONS),
        );
    }

    public function execute(Request $request, Invoice $invoice, ExecuteInvoiceVoid $action): InvoiceResource
    {
        $this->authorize('void', $invoice);

        /** @var User $actor */
        $actor = $request->user();

        return InvoiceResource::make($action->handle($invoice, $actor)->load(self::RELATIONS));
    }

    public function reject(Request $request, Invoice $invoice, RejectInvoiceVoid $action): InvoiceResource
    {
        $this->authorize('void', $invoice);

        /** @var User $actor */
        $actor = $request->user();

        return InvoiceResource::make($action->handle($invoice, $actor)->load(self::RELATIONS));
    }
}
