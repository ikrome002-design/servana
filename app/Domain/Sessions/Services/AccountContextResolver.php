<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Services;

use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Sessions\Support\AccountContext;
use App\Domain\Sessions\Support\AccountContextIdentifier;
use App\Domain\Sessions\Support\BranchContext;
use App\Http\Hosts\AccountHostRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Derives the account contexts a user may currently enter (Phase UI-03; ADR-018 step 2;
 * UI/UX plan §5.3, §14.2 of the phase brief).
 *
 * THE ONLY SOURCE IS THE DATABASE, READ NOW. Nothing here consults the request, the Host header,
 * the session, or any client input. That is what makes ADR-017 hold across a switch: arriving on
 * `citrus.servana.ke` does not put a platform context in this list, and never can — only
 * `users.is_platform_staff` does.
 *
 * ROLE → ACCOUNT MAPPING. The seven `merchant_users.role` values and platform-staff status map
 * one-to-one onto the eight canonical account keys. The mapping lives here, once. The account
 * KEY is an experience selector taken from the UI-02 registry (no second registry is created);
 * the AUTHORITY is, and stays, the membership row.
 */
final class AccountContextResolver
{
    public function __construct(
        private readonly AccountHostRegistry $registry,
        private readonly AccountContextIdentifier $identifier,
    ) {}

    /** The canonical account key for a merchant membership role. */
    public function accountKeyForRole(MerchantUserRole $role): string
    {
        return match ($role) {
            MerchantUserRole::MerchantAdmin => 'merchant_administrator',
            MerchantUserRole::BranchManager => 'merchant_branch',
            MerchantUserRole::Hr => 'merchant_human_resource',
            MerchantUserRole::Finance => 'merchant_finance',
            MerchantUserRole::FrontOffice => 'merchant_front_office',
            MerchantUserRole::Personnel => 'merchant_personnel',
            MerchantUserRole::Audit => 'merchant_audit',
        };
    }

    /** The platform account key. Reached ONLY through `users.is_platform_staff`. */
    public function platformAccountKey(): string
    {
        return 'super_administrator';
    }

    /**
     * Every context the user may enter right now, in a stable order.
     *
     * A context appears only when ALL of its preconditions currently hold. An inactive user, an
     * inactive merchant, a non-active membership, or a branch-scoped role with no active branch
     * assignment simply produces no entry — there is no "listed but unusable" state to exploit.
     *
     * @return list<AccountContext>
     */
    public function forUser(User $user, ?string $environment = null): array
    {
        if (! $user->isActive()) {
            return [];
        }

        $environment ??= $this->registry->environment();
        $contexts = [];

        if ($user->is_platform_staff) {
            $contexts[] = $this->platformContext($user, $environment);
        }

        foreach ($this->activeMembershipsOf($user) as $membership) {
            $context = $this->merchantContext($user, $membership, $environment);

            if ($context !== null) {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    /**
     * Every ACTIVE membership this user holds, across ALL merchants.
     *
     * Read straight from the tables rather than through the `MerchantUser` model. This is an
     * IDENTITY-level resolver that runs before — and in order to decide — tenant context, and the
     * `BelongsToMerchant` global scope would silently constrain it to whichever merchant happened
     * to be resolved, hiding exactly the second-merchant context that account switching exists to
     * reach. `withoutTenancy()` is the wrong instrument (it is reserved for Platform/Tenancy code,
     * and Larastan enforces that); reading the rows directly is honest about what this query is.
     *
     * Isolation is not weakened: the only predicate is `user_id`, taken from the authenticated
     * principal, so nothing user-supplied reaches the query and no row from another human can be
     * returned.
     *
     * @return list<object{id: int, role: string, merchant_id: int, last_branch_id: int|null, merchant_ulid: string, merchant_name: string}>
     */
    private function activeMembershipsOf(User $user): array
    {
        /** @var list<object{id: int, role: string, merchant_id: int, last_branch_id: int|null, merchant_ulid: string, merchant_name: string}> $rows */
        $rows = DB::table('merchant_users')
            ->join('merchants', 'merchants.id', '=', 'merchant_users.merchant_id')
            ->where('merchant_users.user_id', $user->id)
            ->where('merchant_users.status', MerchantUserStatus::Active->value)
            // ACTIVE **or** PENDING_SETUP. A merchant sits in `pending_setup` from
            // self-registration until first-time setup completes, and signing in is exactly what
            // the owner must do to complete it — excluding that state made a freshly registered
            // owner unable to receive a sign-in link at all (caught by MerchantSelfRegistrationTest).
            // Suspended and deactivated merchants are still excluded, which is the state that
            // actually needs to deny. What the owner may DO before setup remains gated by
            // EnsureFirstTimeSetupAccess and EnsureMerchantActive, unchanged.
            ->whereIn('merchants.status', [
                MerchantStatus::Active->value,
                MerchantStatus::PendingSetup->value,
            ])
            ->orderBy('merchant_users.id')
            ->get([
                'merchant_users.id',
                'merchant_users.role',
                'merchant_users.merchant_id',
                'merchant_users.last_branch_id',
                'merchants.ulid as merchant_ulid',
                'merchants.name as merchant_name',
            ])
            ->all();

        return $rows;
    }

    /** The single context matching an account key, or null when the user may not enter it. */
    public function findForUser(User $user, string $accountKey, ?string $environment = null): ?AccountContext
    {
        foreach ($this->forUser($user, $environment) as $context) {
            if ($context->accountKey === $accountKey) {
                return $context;
            }
        }

        return null;
    }

    /** Resolve a browser-supplied opaque id against the freshly derived list, or null. */
    public function findByContextId(User $user, string $contextId, ?string $environment = null): ?AccountContext
    {
        foreach ($this->forUser($user, $environment) as $context) {
            // hash_equals: the id is a keyed digest, so compare it the way a digest is compared.
            if (hash_equals($context->contextId, $contextId)) {
                return $context;
            }
        }

        return null;
    }

    private function platformContext(User $user, string $environment): AccountContext
    {
        $accountKey = $this->platformAccountKey();
        $host = $this->registry->get($accountKey);

        return new AccountContext(
            contextId: $this->identifier->for($user->id, $accountKey),
            accountKey: $accountKey,
            displayName: $host->displayName,
            targetHost: $this->registry->hostForAccount($accountKey, $environment),
            defaultRoute: $host->defaultAuthenticatedRoute,
            // Mandatory MFA for platform staff is Plan §18, resolved by MfaRequirementResolver.
            // The registry flag mirrors it for presentation; it never decides anything.
            requiresMfa: true,
            roleLabel: 'Super Administrator',
        );
    }

    /** @param  object{id: int, role: string, merchant_id: int, last_branch_id: int|null, merchant_ulid: string, merchant_name: string}  $membership */
    private function merchantContext(User $user, object $membership, string $environment): ?AccountContext
    {
        $role = MerchantUserRole::tryFrom($membership->role);

        if ($role === null) {
            return null;
        }

        $accountKey = $this->accountKeyForRole($role);
        $host = $this->registry->get($accountKey);

        $branch = null;

        // Merchant Admin sees all own-merchant branches by role and needs no assignment; every
        // other role is branch-scoped (MerchantUser::isBranchScoped, mirrored here because this
        // resolver reads rows rather than models).
        if ($role !== MerchantUserRole::MerchantAdmin) {
            $branch = $this->resolveBranch((int) $membership->id, (int) $membership->merchant_id, $membership->last_branch_id);

            // A branch-scoped role with no active assignment cannot enter its account at all
            // (Scope §2.3 check 6). Listing it would advertise an entrance that fails.
            if ($branch === null) {
                return null;
            }
        }

        return new AccountContext(
            contextId: $this->identifier->for($user->id, $accountKey, (int) $membership->id, $branch?->id),
            accountKey: $accountKey,
            displayName: $host->displayName,
            targetHost: $this->registry->hostForAccount($accountKey, $environment),
            defaultRoute: $host->defaultAuthenticatedRoute,
            requiresMfa: in_array($role, [MerchantUserRole::MerchantAdmin, MerchantUserRole::Finance], true),
            merchantUlid: (string) $membership->merchant_ulid,
            merchantName: (string) $membership->merchant_name,
            branchUlid: $branch?->ulid,
            branchName: $branch?->name,
            roleLabel: $host->displayName,
            merchantId: (int) $membership->merchant_id,
            branchId: $branch?->id,
            merchantUserId: (int) $membership->id,
        );
    }

    /**
     * The branch a branch-scoped membership enters. `last_branch_id` wins when it is still an
     * ACTIVE assignment; otherwise the lowest active assignment, so the answer is deterministic
     * rather than dependent on row order.
     *
     * Read directly from the tables for the same reason as {@see activeMembershipsOf()}: this runs
     * before tenant context exists. The `merchant_id` predicate on both queries makes the merchant
     * boundary structural rather than merely implied.
     */
    private function resolveBranch(int $merchantUserId, int $merchantId, ?int $preferredBranchId): ?BranchContext
    {
        /** @var list<int> $activeBranchIds */
        $activeBranchIds = DB::table('branch_user_assignments')
            ->where('merchant_user_id', $merchantUserId)
            ->where('merchant_id', $merchantId)
            ->where('status', BranchUserAssignmentStatus::Active->value)
            ->pluck('branch_id')
            ->map(static fn (int $id): int => $id)
            ->all();

        if ($activeBranchIds === []) {
            return null;
        }

        $branchId = ($preferredBranchId !== null && in_array($preferredBranchId, $activeBranchIds, true))
            ? $preferredBranchId
            : min($activeBranchIds);

        $row = DB::table('merchant_branches')
            ->where('id', $branchId)
            ->where('merchant_id', $merchantId)
            ->first(['id', 'ulid', 'name']);

        return $row === null
            ? null
            : new BranchContext((int) $row->id, (string) $row->ulid, (string) $row->name);
    }
}
