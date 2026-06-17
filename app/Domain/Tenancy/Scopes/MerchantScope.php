<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global merchant scope (Plan §8.2). Constrains every query on a tenant-owned
 * model to the resolved merchant: `where {table}.merchant_id = TenantContext::merchantId()`.
 *
 * The filter applies ONLY when a merchant is resolved. With no context (login
 * eligibility, self-registration, platform/console, or a test asserting across
 * tenants outside a request) the scope is a no-op — explicit query predicates
 * govern there, and the `creating` hook in BelongsToMerchant is what guarantees
 * a write can never be unscoped.
 */
final class MerchantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $merchantId = app(TenantContext::class)->merchantId();

        if ($merchantId !== null) {
            $builder->where($model->getTable().'.merchant_id', $merchantId);
        }
    }
}
