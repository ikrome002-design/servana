<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Branch ownership (Plan §8.2). Adds the BranchScope global scope so a
 * branch-scoped role sees only its assigned branches' rows, while a merchant-wide
 * role (Merchant Admin) sees every branch of the resolved merchant — which also
 * provides merchant isolation for branch-owned models that carry no merchant_id
 * column (the scope restricts to this merchant's branches via subquery).
 *
 * Combine with BelongsToMerchant on models that DO carry merchant_id (e.g.
 * StaffInvitation, StaffProfile). Override `branchColumn()` when the foreign key
 * is not `branch_id` (e.g. StaffProfile → `primary_branch_id`).
 *
 * @phpstan-require-extends Model
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /** The branch foreign-key column this model is scoped on. */
    public function branchColumn(): string
    {
        return 'branch_id';
    }
}
