<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global branch scope (Plan §8.2). For a branch-scoped role, constrains a
 * branch-owned model to the role's assigned branches; for a merchant-wide role
 * (Merchant Admin), constrains to every branch of the resolved merchant so
 * models WITHOUT a merchant_id column still cannot leak across tenants.
 *
 * Applies only when a merchant is resolved (same no-context rule as MerchantScope).
 * The branch column defaults to `branch_id`; a model may override via a public
 * `branchColumn()` method (e.g. StaffProfile → `primary_branch_id`).
 */
final class BranchScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $merchantId = $context->merchantId();

        if ($merchantId === null) {
            return; // no context — explicit predicates govern
        }

        $column = $model->getTable().'.'.$this->branchColumn($model);

        if ($context->isBranchScoped()) {
            // Branch-scoped role: only its assigned branches (empty → none).
            $builder->whereIn($column, $context->branchIds());

            return;
        }

        // Merchant-wide role (admin): every branch of this merchant.
        $builder->whereIn($column, function ($query) use ($merchantId): void {
            $query->select('id')->from('merchant_branches')->where('merchant_id', $merchantId);
        });
    }

    private function branchColumn(Model $model): string
    {
        return method_exists($model, 'branchColumn') ? $model->branchColumn() : 'branch_id';
    }
}
