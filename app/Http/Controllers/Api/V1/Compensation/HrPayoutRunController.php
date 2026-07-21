<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Actions\CancelPayoutRunDraft;
use App\Domain\Compensation\Actions\CreatePayoutRunDraft;
use App\Domain\Compensation\Actions\SubmitPayoutRun;
use App\Domain\Compensation\Actions\UpdatePayoutRunDraft;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\CancelPayoutRunDraftRequest;
use App\Http\Requests\Compensation\PayoutRunIndexRequest;
use App\Http\Requests\Compensation\StorePayoutRunRequest;
use App\Http\Requests\Compensation\SubmitPayoutRunRequest;
use App\Http\Requests\Compensation\UpdatePayoutRunDraftRequest;
use App\Http\Resources\PersonnelPayoutRunResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 20H HR payout-run API (Plan §62, §10.2, §19.3). Branch-scoped: HR owns the DRAFT workflow —
 * list/show, create, update-draft, submit (freeze), cancel — and NEVER verifies, approves, or marks
 * paid. Tenant + branch isolation is enforced by the model's `BelongsToMerchant`/`BelongsToBranch`
 * global scopes (route bindings + queries auto-restrict to the actor's merchant + assigned branches; a
 * foreign/out-of-scope ULID 404s). The eligible items are SNAPSHOTTED server-side by the domain action
 * — the browser never supplies items or calculated totals. Thin: authorize → validate → resolve scope →
 * domain action → masked Resource. **Servana moves no money.**
 */
final class HrPayoutRunController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CreatePayoutRunDraft $create,
        private readonly UpdatePayoutRunDraft $update,
        private readonly SubmitPayoutRun $submitAction,
        private readonly CancelPayoutRunDraft $cancelAction,
    ) {}

    public function index(PayoutRunIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAsHr', PersonnelPayoutRun::class);

        $query = PersonnelPayoutRun::query()->withCount('items')->with('branch:id,ulid');
        $this->applyFilters($query, $request);

        return PersonnelPayoutRunResource::collection(
            $query->orderByDesc('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('viewAsHr', PersonnelPayoutRun::class);

        return PersonnelPayoutRunResource::make($this->loadDetail($personnelPayoutRun));
    }

    public function store(StorePayoutRunRequest $request): JsonResponse
    {
        $this->authorize('create', PersonnelPayoutRun::class);

        $branch = $this->resolveBranchInScope((string) $request->validated('branch_ulid'));

        /** @var User $actor */
        $actor = $request->user();

        $run = $this->create->handle(
            $branch,
            (string) $request->validated('period_start'),
            (string) $request->validated('period_end'),
            (string) $request->validated('currency'),
            $actor,
        );

        return PersonnelPayoutRunResource::make($run->load('branch:id,ulid')->loadCount('items'))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePayoutRunDraftRequest $request, PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('update', $personnelPayoutRun);

        /** @var User $actor */
        $actor = $request->user();

        $run = $this->update->handle(
            $personnelPayoutRun,
            (string) $request->validated('period_start'),
            (string) $request->validated('period_end'),
            (string) $request->validated('currency'),
            $actor,
        );

        return PersonnelPayoutRunResource::make($this->loadDetail($run));
    }

    public function submit(SubmitPayoutRunRequest $request, PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('submit', $personnelPayoutRun);

        /** @var User $actor */
        $actor = $request->user();

        return PersonnelPayoutRunResource::make($this->loadDetail($this->submitAction->handle($personnelPayoutRun, $actor)));
    }

    public function cancel(CancelPayoutRunDraftRequest $request, PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('cancel', $personnelPayoutRun);

        /** @var User $actor */
        $actor = $request->user();

        return PersonnelPayoutRunResource::make($this->loadDetail($this->cancelAction->handle($personnelPayoutRun, $actor)));
    }

    private function loadDetail(PersonnelPayoutRun $run): PersonnelPayoutRun
    {
        return $run->load([
            'branch:id,ulid',
            'items.staffProfile:id,ulid,display_name',
            'items.payoutRun:id,ulid',
            'items.earningsStatementFile:id,ulid',
        ])->loadCount('items');
    }

    /** Resolve a branch ULID that the acting HR membership may write to (foreign/out-of-scope → 404). */
    private function resolveBranchInScope(string $branchUlid): MerchantBranch
    {
        /** @var MerchantBranch|null $branch */
        $branch = MerchantBranch::query()->where('ulid', $branchUlid)->first();

        if ($branch === null || ! $this->context->canAccessBranch($branch->id)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $branch;
    }

    /** @param  Builder<PersonnelPayoutRun>  $query */
    private function applyFilters(Builder $query, PayoutRunIndexRequest $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }
        if ($request->filled('currency')) {
            $query->where('currency', (string) $request->string('currency'));
        }
        if ($request->filled('branch_ulid')) {
            $branchId = (int) MerchantBranch::query()->where('ulid', (string) $request->string('branch_ulid'))->value('id');
            $query->where('branch_id', $branchId ?: -1);
        }
    }

    private function perPage(PayoutRunIndexRequest $request): int
    {
        return min(max((int) $request->integer('per_page', 25), 1), 100);
    }
}
