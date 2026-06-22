<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Api\V1\Audit\FiltersAuditLogs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform / governance audit-log read API (Scope §4.8, Plan §70).
 *
 * Returns ONLY the platform chain (merchant_id IS NULL). Super Administrators see
 * platform/governance audit scope; they never gain merchant operational audit via
 * this route. `platform.audit.view` is enforced by route middleware; row-level
 * access by AuditLogPolicy. A merchant-scoped audit ULID 404s here (no leak).
 */
final class PlatformAuditLogController extends Controller
{
    use FiltersAuditLogs;

    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()->whereNull('merchant_id');

        $this->applyFilters($query, $request);

        return AuditLogResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        // Only platform-chain rows are addressable here; a merchant row 404s.
        abort_if($auditLog->merchant_id !== null, 404);
        $this->authorize('view', $auditLog);

        return AuditLogResource::make($auditLog);
    }
}
