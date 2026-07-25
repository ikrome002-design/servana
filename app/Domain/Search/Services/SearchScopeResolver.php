<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Builds the server's own {@see SearchContext} from the AUTHENTICATED membership (Phase 22).
 *
 * This class is the single place a search scope can come from, and it reads exclusively from
 * {@see TenantContext}. The only request-supplied value it will even look at is a list of branch
 * ULIDs, and that value can only ever NARROW the result — it is intersected with the membership's
 * own reachable branches, so naming a foreign branch yields fewer results, never more.
 *
 * Branch ids are always MATERIALIZED to concrete integers:
 *   - branch-scoped membership → the membership's assigned branches;
 *   - merchant-wide membership (Merchant Admin) → every branch of the resolved merchant.
 *
 * `TenantContext::branchIds()` returns an EMPTY array for a merchant-wide role, meaning "all own
 * branches" rather than "none". Search must not inherit that ambiguity: an engine filter needs real
 * integers, and downstream an empty set must always mean "match nothing". Expanding it here is what
 * makes `hasBranchScope()` a safe short-circuit everywhere else.
 */
final class SearchScopeResolver
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $requestedBranchUlids  optional narrowing filter from the request
     */
    public function resolve(User $user, array $requestedBranchUlids = []): ?SearchContext
    {
        $merchantId = $this->context->merchantId();

        if ($merchantId === null) {
            return null; // no tenant context ⇒ no search
        }

        $branchIds = $this->accessibleBranchIds($merchantId);

        if ($requestedBranchUlids !== []) {
            $branchIds = $this->intersectRequested($merchantId, $branchIds, $requestedBranchUlids);
        }

        return new SearchContext(
            user: $user,
            merchantId: $merchantId,
            branchIds: $branchIds,
            isBranchScoped: $this->context->isBranchScoped(),
            staffProfileId: $this->actingStaffProfileId(),
            permissions: $this->context->permissions(),
        );
    }

    /**
     * Every branch this membership may reach, as concrete ids.
     *
     * @return list<int>
     */
    private function accessibleBranchIds(int $merchantId): array
    {
        if ($this->context->isBranchScoped()) {
            return $this->context->branchIds();
        }

        /** @var list<int> $ids */
        $ids = MerchantBranch::query()
            ->where('merchant_id', $merchantId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }

    /**
     * Resolve the requested branch ULIDs INSIDE the merchant, then intersect with what the
     * membership can reach. A foreign or unknown ULID simply does not appear in the intersection —
     * it never raises, so the endpoint reveals nothing about which branches exist elsewhere.
     *
     * @param  list<int>  $accessible
     * @param  list<string>  $requestedUlids
     * @return list<int>
     */
    private function intersectRequested(int $merchantId, array $accessible, array $requestedUlids): array
    {
        /** @var list<int> $requestedIds */
        $requestedIds = MerchantBranch::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('ulid', $requestedUlids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return array_values(array_intersect($accessible, $requestedIds));
    }

    /**
     * The acting user's own staff profile id, derived from the membership. Never accepted from the
     * request — this is what makes own-scope forgery structurally impossible.
     */
    private function actingStaffProfileId(): ?int
    {
        $merchantUser = $this->context->merchantUser();

        if ($merchantUser === null) {
            return null;
        }

        $profile = StaffProfile::query()
            ->where('merchant_user_id', $merchantUser->id)
            ->first();

        return $profile instanceof StaffProfile ? $profile->id : null;
    }
}
