<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Clients;

use App\Domain\Clients\Actions\CreateClient;
use App\Domain\Clients\Actions\UpdateClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Support\ClientContactIndex;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Concerns\ResolvesWriteBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ClientIndexRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Client records (Plan §35; guardrail §6.4). Front Office owns them (`client.*`,
 * `front_office.search`); authority is ClientPolicy + EnsurePermission. Reads are
 * branch-scoped (BranchScope) and ALWAYS masked (ClientResource). Search matches
 * the client name OR the normalized phone via the keyed HMAC blind index — never a
 * plaintext phone column — and never leaks existence across tenants/branches.
 */
final class ClientController extends Controller
{
    use ResolvesWriteBranch;

    public function __construct(private readonly TenantContext $context) {}

    public function index(ClientIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Client::class);

        $filters = $request->validated();
        $hasQuery = isset($filters['q']) && trim((string) $filters['q']) !== '';

        // Search is a distinct capability (front_office.search); listing is client.view.
        abort_if($hasQuery && ! $this->context->can('front_office.search'), 403, 'Client search is not permitted.');

        // BranchScope + MerchantScope already restrict to the caller's branch(es)
        // and merchant; the search below cannot widen that.
        $query = Client::query()->with('consents');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if ($hasQuery) {
            $this->applySearch($query, (string) $filters['q']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'full_name');

        return ClientResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(Client $client): ClientResource
    {
        $this->authorize('view', $client);

        return ClientResource::make($client->load('consents'));
    }

    public function store(StoreClientRequest $request, CreateClient $action): JsonResponse
    {
        $this->authorize('create', Client::class);

        $data = $request->validated();
        $branch = $this->resolveWriteBranch($this->context, $data['branch_id'] ?? null);

        /** @var User $actor */
        $actor = $request->user();
        $client = $action->handle($branch, $actor, $data);

        return ClientResource::make($client->load('consents'))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateClientRequest $request, Client $client, UpdateClient $action): ClientResource
    {
        $this->authorize('update', $client);

        /** @var User $actor */
        $actor = $request->user();

        return ClientResource::make($action->handle($client, $actor, $request->validated())->load('consents'));
    }

    /**
     * Branch/tenant-scoped search by name OR normalized phone (blind index). The
     * raw query is never used as a plaintext phone column; a phone-like term is
     * HMAC-indexed for an exact match, while a name term is a case-insensitive
     * prefix/substring match.
     *
     * @param  Builder<Client>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $phoneIndex = strlen($digits) >= 7 ? ClientContactIndex::for($term) : null;

        $query->where(function (Builder $inner) use ($term, $phoneIndex): void {
            $inner->where('full_name', 'ilike', '%'.$term.'%');

            if ($phoneIndex !== null) {
                $inner->orWhere('phone_index', $phoneIndex);
            }
        });
    }
}
