<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Compensation\Actions\RespondToEarningsQuery;
use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\EarningsQueryIndexRequest;
use App\Http\Requests\Compensation\RespondToEarningsQueryRequest;
use App\Http\Resources\EarningsQueryResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 20H Finance earnings-query responder API (Plan §63, §19.3; D-H12-1). Finance is the sole
 * authoritative responder: it reads the merchant-scoped query work queue and resolves/rejects a query.
 * A monetary correction is created ONLY through the domain action as an additive compensation adjustment
 * (never a silent ledger edit) and linked via `resolved_adjustment_id`. Respond carries MFA (group) +
 * Idempotency-Key at the route (the financial-mutation class); a terminal query fails closed so a replay
 * cannot duplicate a correction. Tenant/branch isolation is enforced by the model global scopes.
 */
final class FinanceEarningsQueryController extends Controller
{
    public function __construct(private readonly RespondToEarningsQuery $respondAction) {}

    public function index(EarningsQueryIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAsResponder', EarningsQuery::class);

        $query = EarningsQuery::query()->with(['staffProfile:id,ulid,display_name', 'resolvedAdjustment:id,ulid']);
        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        return EarningsQueryResource::collection(
            $query->orderByDesc('id')->paginate(min(max((int) $request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function show(EarningsQuery $earningsQuery): EarningsQueryResource
    {
        $this->authorize('viewAsResponder', EarningsQuery::class);

        return EarningsQueryResource::make($earningsQuery->load(['staffProfile:id,ulid,display_name', 'resolvedAdjustment:id,ulid']));
    }

    public function respond(RespondToEarningsQueryRequest $request, EarningsQuery $earningsQuery): EarningsQueryResource
    {
        $this->authorize('respond', $earningsQuery);

        /** @var User $responder */
        $responder = $request->user();

        /** @var array{amount_minor: int, currency: string, reason: string}|null $correction */
        $correction = null;
        if (is_array($request->validated('correction'))) {
            $raw = $request->validated('correction');
            $correction = [
                'amount_minor' => (int) $raw['amount_minor'],
                'currency' => (string) $raw['currency'],
                'reason' => (string) $raw['reason'],
            ];
        }

        $query = $this->respondAction->handle(
            $earningsQuery,
            $responder,
            EarningsQueryStatus::from((string) $request->validated('decision')),
            (string) $request->validated('resolution_note'),
            $correction,
        );

        return EarningsQueryResource::make($query->load(['staffProfile:id,ulid,display_name', 'resolvedAdjustment:id,ulid']));
    }
}
