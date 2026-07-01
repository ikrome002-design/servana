<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Invoicing;

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateInvoiceDraft;
use App\Domain\Invoicing\Actions\FinalizeInvoice;
use App\Domain\Invoicing\Actions\UpdateInvoiceDraft;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoicing\InvoiceIndexRequest;
use App\Http\Requests\Invoicing\StoreInvoiceRequest;
use App\Http\Requests\Invoicing\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Front Office invoice operations (Plan §40; Phase 17). Front Office owns
 * `invoice.view` + `invoice.create` (list/detail/draft/finalize) within its resolved
 * merchant + assigned branch. Reads are branch-scoped (the model's BelongsToBranch
 * global scope) and client contact is ALWAYS masked. Every mutation delegates to a
 * transactional domain action that re-authorizes, locks, validates state, and writes
 * audit events; merchant/branch/status/number/totals/snapshots/actor are derived
 * server-side and never accepted from the body. Finalization is a `financial_mutation`
 * (route-level idempotency).
 */
final class InvoiceController extends Controller
{
    private const RELATIONS = ['client', 'items', 'items.service', 'items.personnel', 'items.serviceSession'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(InvoiceIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->validated();
        $query = Invoice::query()->with(['client', 'items']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return InvoiceResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $this->authorize('view', $invoice);

        return InvoiceResource::make($invoice->load(self::RELATIONS));
    }

    public function store(StoreInvoiceRequest $request, CreateInvoiceDraft $action): InvoiceResource
    {
        $this->authorize('create', Invoice::class);

        /** @var User $actor */
        $actor = $request->user();
        $client = $this->resolveClient((string) $request->validated('client_id'));
        $sessions = $this->resolveSessions($request->validated('service_session_ids', []));

        return InvoiceResource::make(
            $action->handle($client, $sessions, $actor)->load(self::RELATIONS),
        );
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice, UpdateInvoiceDraft $action): InvoiceResource
    {
        $this->authorize('update', $invoice);

        /** @var User $actor */
        $actor = $request->user();
        /** @var Client $client */
        $client = Client::query()->whereKey($invoice->client_id)->firstOrFail();
        $sessions = $this->resolveSessions($request->validated('service_session_ids', []));

        return InvoiceResource::make(
            $action->handle($invoice, $client, $sessions, $actor)->load(self::RELATIONS),
        );
    }

    public function finalize(Invoice $invoice, FinalizeInvoice $action): InvoiceResource
    {
        $this->authorize('finalize', $invoice);

        /** @var User $actor */
        $actor = request()->user();

        return InvoiceResource::make($action->handle($invoice, $actor)->load(self::RELATIONS));
    }

    private function resolveClient(string $ulid): Client
    {
        /** @var Client|null $client */
        $client = Client::query()->where('ulid', $ulid)->first();
        if ($client === null) {
            abort(404); // foreign-tenant / unknown client never leaks existence
        }
        if (! $this->context->canAccessBranch($client->branch_id)) {
            throw TenantAccessException::noBranchScope();
        }

        return $client;
    }

    /**
     * @param  list<string>  $ulids
     * @return list<ServiceSession>
     */
    private function resolveSessions(array $ulids): array
    {
        /** @var list<ServiceSession> $sessions */
        $sessions = ServiceSession::query()->whereIn('ulid', $ulids)->get()->all();
        if (count($sessions) !== count(array_unique($ulids))) {
            abort(404); // a requested session does not exist in this tenant
        }

        return $sessions;
    }
}
