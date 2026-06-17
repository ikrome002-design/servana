<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base for every job that touches tenant data (Plan §8.3).
 *
 * The constructor captures the merchant id (+ optional branch id) at dispatch;
 * `handle()` rehydrates the TenantContext from those ids — re-validating that the
 * merchant still exists and is active — BEFORE the subclass runs any tenant query,
 * then delegates to `handleWithinTenant()`. A job dispatched without a merchant
 * id, or for a merchant that is missing/suspended/deactivated, fails with
 * MissingTenantContext rather than running unscoped.
 *
 * (The Horizon middleware that asserts context platform-wide is Phase 21; the
 * per-job rehydration here is the enforcement mechanism in the meantime.)
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ?int $tenantMerchantId,
        public readonly ?int $tenantBranchId = null,
    ) {}

    final public function handle(): void
    {
        $this->rehydrateTenantContext();

        $this->handleWithinTenant();
    }

    /** Subclasses implement their work here, with TenantContext already bound. */
    abstract protected function handleWithinTenant(): void;

    /**
     * Rebind the TenantContext from the captured ids, re-validating merchant
     * status. Fails safely (never unscoped) when context cannot be established.
     */
    protected function rehydrateTenantContext(): void
    {
        if ($this->tenantMerchantId === null) {
            throw MissingTenantContext::forJob(static::class);
        }

        $merchant = Merchant::query()->find($this->tenantMerchantId);

        if ($merchant === null || ! $merchant->status->isActive()) {
            throw MissingTenantContext::forJob(static::class);
        }

        app(TenantContext::class)->bindForJob($merchant, $this->tenantBranchId);
    }
}
