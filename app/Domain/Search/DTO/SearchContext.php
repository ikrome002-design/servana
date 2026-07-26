<?php

declare(strict_types=1);

namespace App\Domain\Search\DTO;

use App\Domain\Search\Services\SearchScopeResolver;
use App\Models\User;

/**
 * The server's own view of who is searching (Phase 22; decision D-22-01).
 *
 * Every value here is derived from the AUTHENTICATED membership by
 * {@see SearchScopeResolver}. There is no constructor path, setter or
 * request field through which a browser can supply a merchant, a branch, a staff profile, a
 * permission or a role — which is what makes filter forgery structurally impossible rather than
 * merely rejected.
 *
 * `branchIds` is always MATERIALIZED: for a branch-scoped membership it is the membership's own
 * branches (optionally narrowed by an `branch_ulids` request filter); for a merchant-wide
 * membership it is every branch of the resolved merchant. It is never empty-meaning-all, because an
 * engine filter needs concrete integers and "empty" must always mean "no results".
 *
 * @phpstan-type SearchPermissions list<string>
 */
final readonly class SearchContext
{
    /**
     * @param  list<int>  $branchIds  materialized accessible branch ids (empty ⇒ no results)
     * @param  list<string>  $permissions  resolved permission keys for this request
     */
    public function __construct(
        public User $user,
        public int $merchantId,
        public array $branchIds,
        public bool $isBranchScoped,
        public ?int $staffProfileId,
        public array $permissions,
    ) {}

    /** Permission check against the resolved keys — the same semantics as TenantContext::can(). */
    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** True when this context can reach at least one branch; an empty set can match nothing. */
    public function hasBranchScope(): bool
    {
        return $this->branchIds !== [];
    }
}
