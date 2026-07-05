<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Domain\Audit\Actions\DismissFlaggedEvent;
use App\Domain\Audit\Actions\FlagAuditEvent;
use App\Domain\Audit\Actions\ReopenFlaggedEvent;
use App\Domain\Audit\Actions\ResolveFlaggedEvent;
use App\Domain\Audit\Actions\StartFlaggedEventReview;
use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\FlagAuditEventRequest;
use App\Http\Requests\Audit\FlaggedEventResolutionRequest;
use App\Http\Resources\AuditFlaggedEventResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Audit flagged-event review workflow (Plan §13.2, §25, §80; Phase 19). The Audit role
 * flags a branch-scoped audit row and works it through the review lifecycle. Every
 * transition mutates review metadata ONLY — the source audit_logs row is immutable and
 * hash-chain protected. Reads/writes are tenant + branch scoped; foreign ULIDs 404.
 */
final class AuditFlaggedEventController extends Controller
{
    private const RELATIONS = ['auditLog', 'assignee', 'resolver'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditFlaggedEvent::class);

        $query = AuditFlaggedEvent::query()->with(self::RELATIONS);
        if (is_string($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }
        ApiPagination::applySort($query, is_string($request->query('sort')) ? $request->query('sort') : null, 'created_at');

        return AuditFlaggedEventResource::collection(
            $query->paginate(ApiPagination::perPage($request->all()))->withQueryString(),
        );
    }

    public function store(FlagAuditEventRequest $request, FlagAuditEvent $action): JsonResponse
    {
        $this->authorize('create', AuditFlaggedEvent::class);

        $auditLog = $this->resolveAuditLog((string) $request->validated('audit_log'));

        /** @var User $actor */
        $actor = $request->user();
        $note = $request->validated('note');
        $flag = $action->handle($auditLog, $actor, is_string($note) ? $note : null);

        return AuditFlaggedEventResource::make($flag->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(AuditFlaggedEvent $auditFlaggedEvent): AuditFlaggedEventResource
    {
        $this->authorize('view', $auditFlaggedEvent);

        return AuditFlaggedEventResource::make($auditFlaggedEvent->load(self::RELATIONS));
    }

    public function startReview(AuditFlaggedEvent $auditFlaggedEvent, StartFlaggedEventReview $action): AuditFlaggedEventResource
    {
        $this->authorize('updateStatus', $auditFlaggedEvent);

        /** @var User $actor */
        $actor = request()->user();

        return AuditFlaggedEventResource::make($action->handle($auditFlaggedEvent, $actor)->load(self::RELATIONS));
    }

    public function resolve(FlaggedEventResolutionRequest $request, AuditFlaggedEvent $auditFlaggedEvent, ResolveFlaggedEvent $action): AuditFlaggedEventResource
    {
        $this->authorize('resolveMetadata', $auditFlaggedEvent);

        /** @var User $actor */
        $actor = $request->user();

        return AuditFlaggedEventResource::make($action->handle($auditFlaggedEvent, $actor, (string) $request->validated('review_notes'))->load(self::RELATIONS));
    }

    public function dismiss(FlaggedEventResolutionRequest $request, AuditFlaggedEvent $auditFlaggedEvent, DismissFlaggedEvent $action): AuditFlaggedEventResource
    {
        $this->authorize('resolveMetadata', $auditFlaggedEvent);

        /** @var User $actor */
        $actor = $request->user();

        return AuditFlaggedEventResource::make($action->handle($auditFlaggedEvent, $actor, (string) $request->validated('review_notes'))->load(self::RELATIONS));
    }

    public function reopen(AuditFlaggedEvent $auditFlaggedEvent, ReopenFlaggedEvent $action): AuditFlaggedEventResource
    {
        $this->authorize('updateStatus', $auditFlaggedEvent);

        /** @var User $actor */
        $actor = request()->user();

        return AuditFlaggedEventResource::make($action->handle($auditFlaggedEvent, $actor)->load(self::RELATIONS));
    }

    /**
     * Resolve a branch-scoped audit row by public ULID inside tenant scope. A foreign or
     * unknown ULID 404s without enumeration; a wrong-branch row uses the branch-denial
     * posture. audit_logs is cross-cutting, so the tenant check is explicit here.
     */
    private function resolveAuditLog(string $ulid): AuditLog
    {
        $log = AuditLog::query()->where('ulid', $ulid)->first();

        if ($log === null || $log->merchant_id !== $this->context->merchantId()) {
            throw new NotFoundHttpException;
        }
        if ($log->branch_id === null || ! $this->context->canAccessBranch($log->branch_id)) {
            throw TenantAccessException::noBranchScope();
        }

        return $log;
    }
}
