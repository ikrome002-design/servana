<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Branches\Actions\DeleteBranchCalendarException;
use App\Domain\Branches\Actions\SetBranchCalendarException;
use App\Domain\Branches\Models\BranchCalendarException;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Scheduling\Services\AppointmentBranchScheduleValidator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\StoreBranchCalendarExceptionRequest;
use App\Http\Requests\Branches\UpdateBranchCalendarExceptionRequest;
use App\Http\Resources\BranchCalendarExceptionResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branch calendar exceptions — date-specific closures and modified hours (REM-SCR-002B;
 * Plan §7.2, §27.3 Branch Manager "branch profile/calendar", Scope §3.3).
 *
 * Branch Manager, branch-scoped. The table, model and runtime consumer shipped long ago —
 * {@see AppointmentBranchScheduleValidator} already honours every
 * exception type — but no operator surface existed, so a branch could never actually be closed
 * for a public holiday or opened on modified hours. This is that surface and nothing more: no new
 * semantics, no new table, no duplication of the scheduling gate.
 *
 * The row has no ULID (as-built branch configuration), so `(branch, date)` is its public identity:
 * exactly one exception per date, which also keeps the scheduling lookup deterministic. Internal
 * ids, `branch_id`, `merchant_id` and `created_by` are never exposed.
 */
final class BranchCalendarExceptionController extends Controller
{
    /** Widest window a single read may span, so the collection is always bounded (Plan §9 rule 10). */
    private const MAX_RANGE_DAYS = 366;

    /** Default window when the caller supplies none: the current month plus the next two. */
    private const DEFAULT_RANGE_DAYS = 92;

    public function index(Request $request, MerchantBranch $branch): AnonymousResourceCollection
    {
        $this->authorizeScoped($branch);

        $tz = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');
        $from = $this->parseDate($request->query('from'), $tz) ?? CarbonImmutable::now($tz)->startOfMonth();
        $to = $this->parseDate($request->query('to'), $tz) ?? $from->addDays(self::DEFAULT_RANGE_DAYS);

        if ($to->lessThan($from)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The end of the range must not precede its start.');
        }
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The calendar range may not exceed '.self::MAX_RANGE_DAYS.' days.');
        }

        $exceptions = BranchCalendarException::query()
            ->where('branch_id', $branch->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();

        return BranchCalendarExceptionResource::collection($exceptions)->additional([
            'meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function store(
        StoreBranchCalendarExceptionRequest $request,
        MerchantBranch $branch,
        SetBranchCalendarException $action,
    ): JsonResponse {
        $this->authorizeScoped($branch);

        /** @var User $actor */
        $actor = $request->user();
        /** @var array{date: string, type: string, opens_at?: string|null, closes_at?: string|null, reason?: string|null} $attributes */
        $attributes = $request->validated();

        $exception = $action->handle($branch, $attributes, $actor);

        return BranchCalendarExceptionResource::make($exception)->response()->setStatusCode(201);
    }

    public function update(
        UpdateBranchCalendarExceptionRequest $request,
        MerchantBranch $branch,
        string $date,
        SetBranchCalendarException $action,
    ): BranchCalendarExceptionResource {
        $exception = $this->findOrFail($branch, $date);
        $this->authorize('manage', $exception);

        /** @var User $actor */
        $actor = $request->user();
        /** @var array{opens_at?: string|null, closes_at?: string|null, reason?: string|null} $attributes */
        $attributes = $request->validated();

        return BranchCalendarExceptionResource::make($action->update($exception, $attributes, $actor));
    }

    public function destroy(
        Request $request,
        MerchantBranch $branch,
        string $date,
        DeleteBranchCalendarException $action,
    ): JsonResponse {
        $exception = $this->findOrFail($branch, $date);
        $this->authorize('manage', $exception);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($exception, $actor);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Authorize a branch-level calendar operation. The row-level policy needs a row, so for the
     * collection and for creation the same authority is asserted against an unsaved instance bound
     * to this branch — the identical `canAccessBranch` + `branch.calendar.manage` pair.
     */
    private function authorizeScoped(MerchantBranch $branch): void
    {
        $probe = new BranchCalendarException;
        $probe->branch_id = $branch->id;
        $probe->merchant_id = $branch->merchant_id;

        $this->authorize('manage', $probe);
    }

    /** Resolve the exception for a date within the already branch-scoped branch (foreign → 404). */
    private function findOrFail(MerchantBranch $branch, string $date): BranchCalendarException
    {
        $exception = BranchCalendarException::query()
            ->where('branch_id', $branch->id)
            ->whereDate('date', $date)
            ->first();

        if ($exception === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $exception;
    }

    private function parseDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, $timezone)?->startOfDay();
        } catch (\Throwable) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Calendar range dates must be formatted YYYY-MM-DD.');
        }
    }
}
