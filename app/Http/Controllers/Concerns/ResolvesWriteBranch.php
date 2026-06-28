<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the target branch for a branch-owned WRITE (catalogue/clients,
 * Phase 15A). Mirrors the staff-invitation pattern: an explicit `branch_id`
 * (branch ULID) is honoured when accessible; otherwise the single assigned branch
 * is used. Ambiguity (no branch / multiple branches without an explicit choice) is
 * a deterministic error, never a silent guess. Cross-merchant/foreign ULIDs 404.
 */
trait ResolvesWriteBranch
{
    protected function resolveWriteBranch(TenantContext $context, ?string $branchUlid): MerchantBranch
    {
        $merchantId = $context->merchantId();
        abort_if($merchantId === null, 403);

        if ($branchUlid !== null && $branchUlid !== '') {
            /** @var MerchantBranch $branch */
            $branch = MerchantBranch::query()
                ->where('merchant_id', $merchantId)
                ->where('ulid', $branchUlid)
                ->firstOr(fn () => abort(404));

            if (! $context->canAccessBranch($branch->id)) {
                throw TenantAccessException::noBranchScope();
            }

            return $branch;
        }

        $branchIds = $context->branchIds();

        if (count($branchIds) === 1) {
            /** @var MerchantBranch $branch */
            $branch = MerchantBranch::query()->whereKey($branchIds[0])->firstOrFail();

            return $branch;
        }

        if ($branchIds === []) {
            throw TenantAccessException::noBranchScope();
        }

        // Branch-scoped to multiple branches: the caller must disambiguate.
        throw ValidationException::withMessages([
            'branch_id' => ['Select which branch this belongs to.'],
        ]);
    }
}
