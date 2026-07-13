<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\Enums\PlatformFeeEntryType;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformFeeLedgerEntryResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Percentage platform-fee ledger READ API (Plan §51, §19.3; Phase 20E, Increment 6). Merchant scope,
 * masked, under `platform_fee.view`. SERVER-SIDE scope is authoritative: branch-scoped roles (Branch
 * Manager/Audit) see only branch-attributable entries (`branch_id` in their assigned branches);
 * merchant-wide roles (Merchant Admin/Finance) see the whole merchant. Read-only — no ledger mutation.
 * Thin: authorize → scoped query → masked Resource. BelongsToMerchant enforces tenant isolation (a
 * foreign-merchant ULID → 404, no existence leak).
 */
final class PlatformFeeLedgerController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlatformFeeLedgerEntry::class);

        $query = $this->scopedQuery()->orderByDesc('id');

        if (in_array($request->string('entry_type')->value(), PlatformFeeEntryType::values(), true)) {
            $query->where('entry_type', $request->string('entry_type')->value());
        }

        return PlatformFeeLedgerEntryResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PlatformFeeLedgerEntry::class);

        $rows = $this->scopedQuery()
            ->where('entry_type', PlatformFeeEntryType::Earned->value)
            ->selectRaw('currency, count(*) as entry_count, coalesce(sum(gross_platform_fee_minor),0) as gross_minor, coalesce(sum(client_shifted_amount_minor),0) as client_shifted_minor, coalesce(sum(merchant_absorbed_amount_minor),0) as merchant_absorbed_minor')
            ->groupBy('currency')
            ->toBase()
            ->get();

        return response()->json([
            'data' => $rows->map(static fn (object $r): array => [
                'currency' => $r->currency,
                'entry_count' => (int) $r->entry_count,
                'gross_platform_fee_minor' => (int) $r->gross_minor,
                'client_shifted_amount_minor' => (int) $r->client_shifted_minor,
                'merchant_absorbed_amount_minor' => (int) $r->merchant_absorbed_minor,
            ])->values()->all(),
        ]);
    }

    public function show(PlatformFeeLedgerEntry $platformFeeLedgerEntry): PlatformFeeLedgerEntryResource
    {
        $this->authorize('view', $platformFeeLedgerEntry);

        // Branch-scoped roles may only see branch-attributable entries (no cross-branch leak).
        if ($this->context->isBranchScoped()
            && ! in_array($platformFeeLedgerEntry->branch_id, $this->context->branchIds(), true)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return PlatformFeeLedgerEntryResource::make(
            $platformFeeLedgerEntry->load(['merchant', 'branch', 'sourceInvoice', 'sourceInvoiceItem', 'subscriptionInvoiceItem']),
        );
    }

    /**
     * The tenant-scoped query, additionally restricted to the actor's assigned branches when the actor
     * is branch-scoped (Branch Manager/Audit). BelongsToMerchant applies the merchant scope.
     *
     * @return Builder<PlatformFeeLedgerEntry>
     */
    private function scopedQuery()
    {
        $query = PlatformFeeLedgerEntry::query()->with(['merchant', 'branch', 'sourceInvoice']);

        if ($this->context->isBranchScoped()) {
            $query->whereIn('branch_id', $this->context->branchIds());
        }

        return $query;
    }
}
