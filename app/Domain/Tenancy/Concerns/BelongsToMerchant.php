<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\Scopes\BranchScope;
use App\Domain\Tenancy\Scopes\MerchantScope;
use App\Domain\Tenancy\Services\LogUnauthorizedAttempt;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant ownership (Plan §8.2). A model using this trait:
 *
 *   - is constrained to the resolved merchant on every query (MerchantScope);
 *   - has its `merchant_id` auto-filled from TenantContext on create, throwing
 *     MissingTenantContext when neither an explicit `merchant_id` nor a resolved
 *     merchant is available — a tenant row can never be written unscoped;
 *   - resolves route bindings WITHIN merchant scope, so a foreign-merchant ULID
 *     yields 404 (never 403 — no existence leak) and writes an `unauthorized_access`
 *     audit row when the ULID exists in another tenant.
 *
 * `withoutTenancy()` is the ONLY sanctioned scope escape and is restricted by the
 * `NoWithoutTenancyOutsidePlatformRule` PHPStan rule to Platform / Tenancy /
 * audited-job code.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToMerchant
{
    public static function bootBelongsToMerchant(): void
    {
        static::addGlobalScope(new MerchantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('merchant_id') !== null) {
                return; // explicit merchant_id (onboarding, platform, seeds) wins
            }

            $merchantId = app(TenantContext::class)->merchantId();

            if ($merchantId === null) {
                throw MissingTenantContext::forModel(static::class);
            }

            $model->setAttribute('merchant_id', $merchantId);
        });
    }

    /**
     * Sanctioned tenant-scope escape (Plan §8.2). Removes the merchant + branch
     * global scopes. Callable only from Platform / Tenancy / audited-job code —
     * enforced by NoWithoutTenancyOutsidePlatformRule.
     *
     * @return Builder<static>
     */
    public static function withoutTenancy(): Builder
    {
        return static::query()
            ->withoutGlobalScope(MerchantScope::class)
            ->withoutGlobalScope(BranchScope::class);
    }

    /**
     * Resolve a route binding within merchant scope (Plan §8.2). Branch-level
     * authority is intentionally NOT applied here (it is enforced by the model
     * policy / EnsureBranchScope as a 403); only cross-merchant access is a 404.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();

        $model = static::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where($this->qualifyColumn($field), $value)
            ->first();

        if ($model === null) {
            // Audit the attempt iff the ULID exists in another tenant (no leak).
            app(LogUnauthorizedAttempt::class)->record(static::class, $field, (string) $value);
        }

        return $model;
    }
}
