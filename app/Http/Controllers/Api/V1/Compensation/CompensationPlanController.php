<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Compensation\Actions\ApproveCompensationPlan;
use App\Domain\Compensation\Actions\BuildCompensationPlanImpactPreview;
use App\Domain\Compensation\Actions\CancelCompensationPlan;
use App\Domain\Compensation\Actions\CreateCompensationPlanDraft;
use App\Domain\Compensation\Actions\RejectCompensationPlan;
use App\Domain\Compensation\Actions\SubmitCompensationPlan;
use App\Domain\Compensation\Actions\UpdateCompensationPlanDraft;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CompensationPlanHistory;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Hr\Models\StaffProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\ApproveCompensationPlanRequest;
use App\Http\Requests\Compensation\CancelCompensationPlanRequest;
use App\Http\Requests\Compensation\RejectCompensationPlanRequest;
use App\Http\Requests\Compensation\StoreCompensationPlanRequest;
use App\Http\Requests\Compensation\SubmitCompensationPlanRequest;
use App\Http\Requests\Compensation\UpdateCompensationPlanDraftRequest;
use App\Http\Resources\CompensationPlanHistoryResource;
use App\Http\Resources\CompensationPlanResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR compensation-plan configuration API (Plan §59, §80; Scope §12.2-§12.9; Phase 20F). Branch
 * scoped, HR only. One named route per transition — NO generic status route, NO DELETE, and NO
 * manual supersede route (supersede is a CONSEQUENCE of approving/activating a successor).
 *
 * Thin: authorize → validate → resolve references inside tenant+branch scope → domain action →
 * masked Resource. The controller never writes a model field, never assigns a status, and never
 * computes money. Typed domain exceptions render their own safe envelopes.
 *
 * **Configuration only** — no route here creates a salary/commission ledger row, a payout, or an
 * earnings statement (Phases 20G/20H own those).
 */
final class CompensationPlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PersonnelCompensationPlan::class);

        $query = PersonnelCompensationPlan::query()
            ->with(['staffProfile', 'branch', 'commissionRule'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id');

        if (in_array($request->string('status')->value(), CompensationPlanStatus::values(), true)) {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('staff_profile_id')) {
            // Resolved inside tenant+branch scope, so a foreign ULID resolves to nothing and the
            // filter then matches no rows rather than leaking another branch's plans.
            $staffProfile = StaffProfile::query()->where('ulid', $request->string('staff_profile_id')->value())->first();
            $query->where('staff_profile_id', $staffProfile instanceof StaffProfile ? $staffProfile->id : 0);
        }

        return CompensationPlanResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function show(PersonnelCompensationPlan $compensationPlan): CompensationPlanResource
    {
        $this->authorize('view', $compensationPlan);

        return CompensationPlanResource::make(
            $compensationPlan->load(['staffProfile', 'branch', 'commissionRule', 'supersedesPlan']),
        );
    }

    public function store(StoreCompensationPlanRequest $request, CreateCompensationPlanDraft $action): JsonResponse
    {
        $this->authorize('create', PersonnelCompensationPlan::class);

        $staffProfile = $this->resolveStaffProfile((string) $request->validated('staff_profile_id'));
        $commissionRule = $this->resolveCommissionRule($request->input('commission_rule_id'));

        $plan = $action->handle(
            staffProfile: $staffProfile,
            branchId: (int) $staffProfile->primary_branch_id,
            actor: $this->actor($request),
            model: CompensationModel::from((string) $request->validated('compensation_model')),
            effectiveFrom: (string) $request->validated('effective_from'),
            changeReason: (string) $request->validated('change_reason'),
            commissionRule: $commissionRule,
            salaryAmountMinor: $this->intOrNull($request->input('salary_amount_minor')),
            salaryCurrency: $request->input('salary_currency'),
            salaryPeriod: $this->salaryPeriod($request->input('salary_period')),
            salaryPayoutDay: $this->intOrNull($request->input('salary_payout_day')),
            effectiveTo: $request->input('effective_to'),
            notes: $request->input('notes'),
        );

        return CompensationPlanResource::make($plan->load(['staffProfile', 'branch', 'commissionRule']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateDraft(
        UpdateCompensationPlanDraftRequest $request,
        PersonnelCompensationPlan $compensationPlan,
        UpdateCompensationPlanDraft $action,
    ): CompensationPlanResource {
        $this->authorize('updateDraft', $compensationPlan);

        $plan = $action->handle(
            plan: $compensationPlan,
            actor: $this->actor($request),
            model: CompensationModel::from((string) $request->validated('compensation_model')),
            effectiveFrom: (string) $request->validated('effective_from'),
            changeReason: (string) $request->validated('change_reason'),
            commissionRule: $this->resolveCommissionRule($request->input('commission_rule_id')),
            salaryAmountMinor: $this->intOrNull($request->input('salary_amount_minor')),
            salaryCurrency: $request->input('salary_currency'),
            salaryPeriod: $this->salaryPeriod($request->input('salary_period')),
            salaryPayoutDay: $this->intOrNull($request->input('salary_payout_day')),
            effectiveTo: $request->input('effective_to'),
            notes: $request->input('notes'),
        );

        return CompensationPlanResource::make($plan->load(['staffProfile', 'branch', 'commissionRule']));
    }

    public function submit(
        SubmitCompensationPlanRequest $request,
        PersonnelCompensationPlan $compensationPlan,
        SubmitCompensationPlan $action,
    ): CompensationPlanResource {
        $this->authorize('submit', $compensationPlan);

        return CompensationPlanResource::make(
            $action->handle($compensationPlan, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['staffProfile', 'branch', 'commissionRule']),
        );
    }

    public function approve(
        ApproveCompensationPlanRequest $request,
        PersonnelCompensationPlan $compensationPlan,
        ApproveCompensationPlan $action,
        BuildCompensationPlanImpactPreview $preview,
    ): CompensationPlanResource {
        $this->authorize('approve', $compensationPlan);

        // F8: the preview is built SERVER-SIDE and only when the approver acknowledged seeing one.
        // Without the acknowledgement a backdated approval fails closed inside the action; the
        // preview is never accepted from the client.
        $impactPreview = $request->boolean('acknowledge_impact_preview')
            ? $preview->handle($compensationPlan)
            : null;

        return CompensationPlanResource::make(
            $action->handle(
                $compensationPlan,
                $this->actor($request),
                (string) $request->validated('change_reason'),
                // The route's RequireFreshMfa already rejected a missing/stale step-up; the action
                // re-asserts it so the domain can never approve without one.
                hasFreshStepUp: true,
                impactPreview: $impactPreview,
            )->load(['staffProfile', 'branch', 'commissionRule', 'supersedesPlan']),
        );
    }

    public function reject(
        RejectCompensationPlanRequest $request,
        PersonnelCompensationPlan $compensationPlan,
        RejectCompensationPlan $action,
    ): CompensationPlanResource {
        $this->authorize('reject', $compensationPlan);

        return CompensationPlanResource::make(
            $action->handle($compensationPlan, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['staffProfile', 'branch', 'commissionRule']),
        );
    }

    public function cancel(
        CancelCompensationPlanRequest $request,
        PersonnelCompensationPlan $compensationPlan,
        CancelCompensationPlan $action,
    ): CompensationPlanResource {
        $this->authorize('cancel', $compensationPlan);

        return CompensationPlanResource::make(
            $action->handle($compensationPlan, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['staffProfile', 'branch', 'commissionRule']),
        );
    }

    /** Append-only compensation change history for one plan (`compensation.history.view`). */
    public function history(Request $request, PersonnelCompensationPlan $compensationPlan): AnonymousResourceCollection
    {
        $this->authorize('viewHistory', $compensationPlan);

        $query = CompensationPlanHistory::query()
            ->where('compensation_plan_id', $compensationPlan->id)
            ->with('actor')
            ->orderByDesc('id');

        return CompensationPlanHistoryResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    /**
     * Resolve the subject INSIDE tenant + branch scope. HR is same-branch only, so a foreign or
     * out-of-branch ULID must be indistinguishable from one that does not exist → 404.
     */
    private function resolveStaffProfile(string $ulid): StaffProfile
    {
        $staffProfile = StaffProfile::query()->where('ulid', $ulid)->first();
        abort_if($staffProfile === null, Response::HTTP_NOT_FOUND);

        return $staffProfile;
    }

    private function resolveCommissionRule(?string $ulid): ?CommissionRule
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $rule = CommissionRule::query()->where('ulid', $ulid)->first();
        abort_if($rule === null, Response::HTTP_NOT_FOUND);

        return $rule;
    }

    private function salaryPeriod(?string $value): ?SalaryPeriod
    {
        return $value === null || $value === '' ? null : SalaryPeriod::from($value);
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
