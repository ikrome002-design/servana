<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Compensation\Actions\CreateCommissionRuleDraft;
use App\Domain\Compensation\Actions\UpdateCommissionRuleDraft;
use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Exceptions\CompensationScopeException;
use App\Domain\Compensation\Exceptions\CompensationValidationException;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\StoreCommissionRuleRequest;
use App\Http\Requests\Compensation\UpdateCommissionRuleDraftRequest;
use App\Http\Resources\CommissionRuleResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR commission-rule configuration API (Plan §59; Scope §12.7 Step 3A, §18.3; Phase 20F). Branch
 * scoped, HR only, governed by the `compensation.plan.*` keys (the matrix declares no
 * `commission.rule.*` namespace).
 *
 * Only CREATE and EDIT-DRAFT exist as routes. A rule has no independent lifecycle: submit, approve,
 * activate, reject, cancel and END are consequences of the referencing plan's transitions and run
 * inside that plan's transaction — so there is NO submit/approve/cancel route here, and NO DELETE
 * (a previously active rule is ENDED, not deleted).
 *
 * **Configuration only** — computes no commission, creates no ledger row.
 */
final class CommissionRuleController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommissionRule::class);

        $query = CommissionRule::query()
            ->with(['serviceCategory', 'selectedServices'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id');

        if (in_array($request->string('status')->value(), CommissionRuleStatus::values(), true)) {
            $query->where('status', $request->string('status')->value());
        }

        return CommissionRuleResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function show(CommissionRule $commissionRule): CommissionRuleResource
    {
        $this->authorize('view', $commissionRule);

        return CommissionRuleResource::make($commissionRule->load(['serviceCategory', 'selectedServices']));
    }

    public function store(StoreCommissionRuleRequest $request, CreateCommissionRuleDraft $action): JsonResponse
    {
        $this->authorize('create', CommissionRule::class);

        $branch = $this->resolveActingBranch();

        $rule = $action->handle(
            branch: $branch,
            actor: $this->actor($request),
            calculationType: CommissionCalculationType::from((string) $request->validated('calculation_type')),
            calculationBasis: CommissionCalculationBasis::from((string) $request->validated('calculation_basis')),
            appliesTo: CommissionAppliesTo::from((string) $request->validated('applies_to')),
            effectiveFrom: (string) $request->validated('effective_from'),
            changeReason: (string) $request->validated('change_reason'),
            percentageBasisPoints: $this->intOrNull($request->input('percentage_basis_points')),
            fixedAmountMinor: $this->intOrNull($request->input('fixed_amount_minor')),
            currency: $request->input('currency'),
            serviceCategory: $this->resolveServiceCategory($request->input('service_category_id')),
            appliesToPreferredPersonnelFee: $request->boolean('applies_to_preferred_personnel_fee'),
            effectiveTo: $request->input('effective_to'),
            notes: $request->input('notes'),
            selectedServices: $this->resolveSelectedServices($request, (int) $branch->merchant_id, (int) $branch->id),
        );

        return CommissionRuleResource::make($rule->load(['serviceCategory', 'selectedServices']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateDraft(
        UpdateCommissionRuleDraftRequest $request,
        CommissionRule $commissionRule,
        UpdateCommissionRuleDraft $action,
    ): CommissionRuleResource {
        $this->authorize('updateDraft', $commissionRule);

        $rule = $action->handle(
            rule: $commissionRule,
            actor: $this->actor($request),
            calculationType: CommissionCalculationType::from((string) $request->validated('calculation_type')),
            calculationBasis: CommissionCalculationBasis::from((string) $request->validated('calculation_basis')),
            appliesTo: CommissionAppliesTo::from((string) $request->validated('applies_to')),
            effectiveFrom: (string) $request->validated('effective_from'),
            changeReason: (string) $request->validated('change_reason'),
            percentageBasisPoints: $this->intOrNull($request->input('percentage_basis_points')),
            fixedAmountMinor: $this->intOrNull($request->input('fixed_amount_minor')),
            currency: $request->input('currency'),
            serviceCategory: $this->resolveServiceCategory($request->input('service_category_id')),
            appliesToPreferredPersonnelFee: $request->boolean('applies_to_preferred_personnel_fee'),
            effectiveTo: $request->input('effective_to'),
            notes: $request->input('notes'),
            selectedServices: $this->resolveSelectedServices($request, (int) $commissionRule->merchant_id, (int) $commissionRule->branch_id),
        );

        return CommissionRuleResource::make($rule->load(['serviceCategory', 'selectedServices']));
    }

    /**
     * The branch a rule is created in. HR is branch-scoped, so the acting context resolves exactly
     * one branch; a merchant-wide actor must not be able to create branch configuration blindly.
     */
    private function resolveActingBranch(): MerchantBranch
    {
        $branchIds = $this->context->branchIds();

        abort_if(count($branchIds) !== 1, Response::HTTP_FORBIDDEN, 'A single acting branch is required.');

        $branch = MerchantBranch::query()->whereKey($branchIds[0])->first();
        abort_if($branch === null, Response::HTTP_NOT_FOUND);

        return $branch;
    }

    private function resolveServiceCategory(?string $ulid): ?ServiceCategory
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $category = ServiceCategory::query()->where('ulid', $ulid)->first();
        abort_if($category === null, Response::HTTP_NOT_FOUND);

        return $category;
    }

    /**
     * Resolve the request's `selected_service_ulids` to Service models inside the acting merchant+branch
     * (§9.1). Only `selected_services` carries a set. A ULID that does not resolve within the merchant, or
     * a service from another branch, is indistinguishable from one that does not exist → 404 (never leaks
     * existence). An archived service cannot be newly selected → 422. The server owns identities; the
     * browser never supplies branch ownership. Duplicates are collapsed before resolution.
     *
     * @return list<Service>
     */
    private function resolveSelectedServices(StoreCommissionRuleRequest $request, int $merchantId, int $branchId): array
    {
        if ((string) $request->validated('applies_to') !== CommissionAppliesTo::SelectedServices->value) {
            return [];
        }

        /** @var mixed $raw */
        $raw = $request->validated('selected_service_ulids', []);
        $ulids = is_array($raw) ? array_values(array_unique(array_map('strval', $raw))) : [];
        if ($ulids === []) {
            return [];
        }

        $services = Service::query()->whereIn('ulid', $ulids)->get();

        // Every requested ULID must resolve within the acting merchant scope.
        if ($services->count() !== count($ulids)) {
            throw CompensationScopeException::service();
        }

        foreach ($services as $service) {
            if ((int) $service->merchant_id !== $merchantId || (int) $service->branch_id !== $branchId) {
                throw CompensationScopeException::service();
            }
            if ($service->status === ServiceStatus::Archived) {
                throw CompensationValidationException::selectedServices(
                    'An archived service cannot be selected for a commission rule.',
                );
            }
        }

        return array_values($services->all());
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
