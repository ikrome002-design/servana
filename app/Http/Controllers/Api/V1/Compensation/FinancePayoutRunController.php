<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Actions\ApprovePayoutRunStandard;
use App\Domain\Compensation\Actions\MarkPayoutRunPaid;
use App\Domain\Compensation\Actions\RejectPayoutRun;
use App\Domain\Compensation\Actions\VerifyPayoutRun;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\ApprovePayoutRunRequest;
use App\Http\Requests\Compensation\MarkPayoutRunPaidRequest;
use App\Http\Requests\Compensation\PayoutRunIndexRequest;
use App\Http\Requests\Compensation\RejectPayoutRunRequest;
use App\Http\Requests\Compensation\VerifyPayoutRunRequest;
use App\Http\Resources\PersonnelPayoutRunResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 20H Finance payout-run API (Plan §62, §25.5, §19.3). Finance verifies a submitted run (routing
 * high-value runs to Merchant-Admin approval), approves ordinary runs, rejects (releasing claimed
 * ledgers), and marks approved runs PAID after an EXTERNAL settlement. Mark-paid + verify + approve
 * carry MFA (group) + fresh step-up + Idempotency-Key at the route; reject carries MFA +
 * Idempotency-Key. Tenant/branch isolation is enforced by the model global scopes; the domain actions
 * enforce the financial state machine under a row lock. **Servana moves no money — mark-paid records an
 * external settlement outcome only; there is no provider/Wallet call and no Gate-W dependency.**
 */
final class FinancePayoutRunController extends Controller
{
    public function __construct(
        private readonly VerifyPayoutRun $verifyAction,
        private readonly ApprovePayoutRunStandard $approveAction,
        private readonly RejectPayoutRun $rejectAction,
        private readonly MarkPayoutRunPaid $markPaidAction,
    ) {}

    public function index(PayoutRunIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAsFinance', PersonnelPayoutRun::class);

        $query = PersonnelPayoutRun::query()->withCount('items')->with('branch:id,ulid');
        $this->applyFilters($query, $request);

        return PersonnelPayoutRunResource::collection(
            $query->orderByDesc('id')->paginate(min(max((int) $request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function show(PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('viewAsFinance', PersonnelPayoutRun::class);

        return PersonnelPayoutRunResource::make($this->loadDetail($personnelPayoutRun));
    }

    public function verify(VerifyPayoutRunRequest $request, PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('verify', $personnelPayoutRun);

        /** @var User $actor */
        $actor = $request->user();

        return PersonnelPayoutRunResource::make($this->loadDetail($this->verifyAction->handle($personnelPayoutRun, $actor)));
    }

    public function approve(ApprovePayoutRunRequest $request, PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('approveStandard', $personnelPayoutRun);

        /** @var User $actor */
        $actor = $request->user();

        return PersonnelPayoutRunResource::make($this->loadDetail($this->approveAction->handle($personnelPayoutRun, $actor)));
    }

    public function reject(RejectPayoutRunRequest $request, PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('reject', $personnelPayoutRun);

        /** @var User $actor */
        $actor = $request->user();

        $run = $this->rejectAction->handle($personnelPayoutRun, $actor, (string) $request->validated('reason'));

        return PersonnelPayoutRunResource::make($this->loadDetail($run));
    }

    public function markPaid(MarkPayoutRunPaidRequest $request, PersonnelPayoutRun $personnelPayoutRun): PersonnelPayoutRunResource
    {
        $this->authorize('markPaid', $personnelPayoutRun);

        /** @var User $actor */
        $actor = $request->user();

        $run = $this->markPaidAction->handle(
            $personnelPayoutRun,
            $actor,
            (string) $request->validated('external_payment_reference'),
            (string) $request->validated('paid_date'),
        );

        return PersonnelPayoutRunResource::make($this->loadDetail($run));
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
}
