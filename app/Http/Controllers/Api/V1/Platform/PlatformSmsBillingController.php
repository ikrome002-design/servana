<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\CancelScheduledSmsBillingRule;
use App\Domain\Billing\Actions\ScheduleSmsBillingRule;
use App\Domain\Billing\Enums\PlatformSmsBillingRuleState;
use App\Domain\Billing\Models\PlatformSmsBillingRule;
use App\Domain\Billing\Queries\ResolveEffectiveSmsBillingRule;
use App\Domain\Billing\Queries\SmsBillingChargeReconciliationProjection;
use App\Domain\Billing\Queries\SmsBillingUsageProjection;
use App\Domain\Billing\Services\SmsBillingCostNoticeGenerator;
use App\Domain\Merchants\Models\Merchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CancelSmsBillingRuleRequest;
use App\Http\Requests\Platform\ScheduleSmsBillingRuleRequest;
use App\Http\Requests\Platform\SmsBillingCostNoticePreviewRequest;
use App\Http\Requests\Platform\SmsBillingUsageQueryRequest;
use App\Http\Resources\SmsBillingRuleResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform SMS billing settings, usage and charge reconciliation (COR-UI08-001 §9; Phase UI-08).
 *
 * Super-Admin platform scope. Reads require `platform.billing_settings.view`; scheduling and
 * withdrawing a rule require `platform.billing_settings.update` plus MFA and a fresh
 * `billing_configuration` step-up, all enforced by the route middleware. No SMS-specific
 * permission key exists.
 *
 * Thin: authorize → action/projection → resource. Every response is an aggregate or a
 * configuration row; nothing here can return a recipient, a phone number or a message body.
 */
final class PlatformSmsBillingController extends Controller
{
    public function __construct(
        private readonly ResolveEffectiveSmsBillingRule $rules,
        private readonly SmsBillingCostNoticeGenerator $notices,
    ) {}

    /** The rule in force now, the next scheduled rule, and the inherited currency. */
    public function show(): JsonResponse
    {
        $this->authorize('view', PlatformSmsBillingRule::class);

        $now = CarbonImmutable::now();
        $currency = $this->notices->currencyAt($now)->value;

        $current = $this->rules->at($now);
        $next = $this->rules->next($now);

        // The resources are nested as RESOURCES, not as `->resolve()` arrays. JsonResource is
        // JsonSerializable, so the emitted JSON is byte-identical either way — but `->resolve()`
        // erases the type, and the OpenAPI generator (Scramble) infers the response schema from
        // the static return type. Under `->resolve()` it published `current`/`next` as untyped
        // ARRAYS and never registered a SmsBillingRuleResource component at all, so every
        // generated client was typed wrongly for an object it actually receives.
        return response()->json([
            'data' => [
                'current' => $current === null
                    ? null
                    : new SmsBillingRuleResource($current, PlatformSmsBillingRuleState::Effective, $currency),
                'next' => $next === null
                    ? null
                    : new SmsBillingRuleResource($next, PlatformSmsBillingRuleState::Pending, $currency),
                'currency' => $currency,
                'currency_authority' => 'platform_billing_settings',
            ],
        ]);
    }

    /** The full version series, newest first, each row carrying its derived state. */
    public function versions(): JsonResponse
    {
        $this->authorize('view', PlatformSmsBillingRule::class);

        $now = CarbonImmutable::now();
        $currency = $this->notices->currencyAt($now)->value;

        $rules = PlatformSmsBillingRule::query()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(25);

        // The single effective rule is the first uncancelled row that has already taken effect;
        // every uncancelled row below it is superseded. Derived once for the page, not per row.
        $seenEffective = false;
        $data = [];

        foreach ($rules as $rule) {
            $state = $rule->stateAt($now, $seenEffective);

            if ($state === PlatformSmsBillingRuleState::Effective) {
                $seenEffective = true;
            }

            $data[] = new SmsBillingRuleResource($rule, $state, $currency);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $rules->currentPage(),
                'last_page' => $rules->lastPage(),
                'per_page' => $rules->perPage(),
                'total' => $rules->total(),
            ],
        ]);
    }

    public function schedule(ScheduleSmsBillingRuleRequest $request, ScheduleSmsBillingRule $action): JsonResponse
    {
        $this->authorize('update', PlatformSmsBillingRule::class);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array{unit_cost_minor:int,effective_from:string,reason:string,tax_basis_points?:int|null,usage_warning_threshold_units?:int|null,usage_anomaly_threshold_basis_points?:int|null} $data */
        $data = $request->validated();

        $rule = $action->handle($data, $actor);

        return response()->json([
            'data' => new SmsBillingRuleResource(
                $rule,
                PlatformSmsBillingRuleState::Pending,
                $this->notices->currencyAt(CarbonImmutable::now())->value,
            ),
        ], Response::HTTP_CREATED);
    }

    public function cancel(
        CancelSmsBillingRuleRequest $request,
        PlatformSmsBillingRule $smsBillingRule,
        CancelScheduledSmsBillingRule $action,
    ): JsonResponse {
        $this->authorize('update', PlatformSmsBillingRule::class);

        /** @var User $actor */
        $actor = $request->user();

        $cancelled = $action->handle($smsBillingRule, (string) $request->validated('reason'), $actor);

        return response()->json([
            'data' => new SmsBillingRuleResource(
                $cancelled,
                PlatformSmsBillingRuleState::Cancelled,
                $this->notices->currencyAt(CarbonImmutable::now())->value,
            ),
        ]);
    }

    /** Server-authoritative cost notice for a hypothetical campaign shape. */
    public function costNoticePreview(SmsBillingCostNoticePreviewRequest $request): JsonResponse
    {
        $this->authorize('view', PlatformSmsBillingRule::class);

        $asOf = $request->validated('as_of') !== null
            ? CarbonImmutable::parse((string) $request->validated('as_of'))
            : CarbonImmutable::now();

        return response()->json([
            'data' => $this->notices->preview(
                (int) $request->validated('recipient_count'),
                (int) $request->validated('segment_count'),
                $asOf,
            ),
        ]);
    }

    public function usage(SmsBillingUsageQueryRequest $request, SmsBillingUsageProjection $projection): JsonResponse
    {
        $this->authorize('view', PlatformSmsBillingRule::class);

        $merchantUlid = $request->validated('merchant');
        $merchantId = null;

        if (is_string($merchantUlid)) {
            // An unknown ULID yields no rows rather than an error, so the endpoint cannot be used
            // to probe which merchant identifiers exist.
            $merchantId = Merchant::query()->where('ulid', $merchantUlid)->value('id');
            $merchantId = $merchantId === null ? -1 : (int) $merchantId;
        }

        $page = $projection->paginate([
            'merchant_id' => $merchantId,
            'from' => $request->validated('from') !== null ? CarbonImmutable::parse((string) $request->validated('from')) : null,
            'to' => $request->validated('to') !== null ? CarbonImmutable::parse((string) $request->validated('to')) : null,
        ], (int) ($request->validated('per_page') ?? 25));

        $rows = collect($page->items());

        // Internal bigint ids never leave the boundary: they are translated to public ULIDs here.
        $merchantUlids = Merchant::query()
            ->whereIn('id', $rows->pluck('merchant_id')->unique()->all())
            ->pluck('ulid', 'id');

        return response()->json([
            'data' => $rows->map(static fn (array $row): array => [
                'usage_month' => $row['usage_month'],
                'merchant_id' => $merchantUlids[$row['merchant_id']] ?? null,
                'currency' => $row['currency'],
                'message_count' => $row['message_count'],
                'recipient_count' => $row['recipient_count'],
                'billable_units' => $row['billable_units'],
                'amount_minor' => $row['amount_minor'],
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function chargeReconciliation(SmsBillingChargeReconciliationProjection $projection): JsonResponse
    {
        $this->authorize('view', PlatformSmsBillingRule::class);

        return response()->json(['data' => $projection->summary()]);
    }
}
