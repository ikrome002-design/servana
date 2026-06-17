<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\Jobs\TenantAwareJob;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('tenancy', 'isolation');

/*
 | TenantAwareJob (Plan §8.3, §8.4). A job that touches tenant data captures the
 | merchant id at dispatch and rehydrates TenantContext before running, failing
 | safely (MissingTenantContext) when the merchant id is absent or the merchant is
 | not active. A concrete test job records the merchant id it observed inside
 | handle() so we can prove context was bound.
 */

/** @var array<string, int|null> */
$GLOBALS['tenant_job_observed'] = ['merchant_id' => null];

final class ProbeTenantJob extends TenantAwareJob
{
    protected function handleWithinTenant(): void
    {
        $GLOBALS['tenant_job_observed']['merchant_id'] = app(TenantContext::class)->merchantId();
    }
}

it('rehydrates the tenant context inside the job', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $GLOBALS['tenant_job_observed']['merchant_id'] = null;

    (new ProbeTenantJob($merchant->id))->handle();

    expect($GLOBALS['tenant_job_observed']['merchant_id'])->toBe($merchant->id);
});

it('fails with MissingTenantContext when dispatched without a merchant id', function (): void {
    expect(fn () => (new ProbeTenantJob(null))->handle())
        ->toThrow(MissingTenantContext::class);
});

it('fails with MissingTenantContext when the merchant is suspended', function (): void {
    $merchant = Merchant::factory()->create(['status' => MerchantStatus::Suspended]);

    expect(fn () => (new ProbeTenantJob($merchant->id))->handle())
        ->toThrow(MissingTenantContext::class);
});

it('fails with MissingTenantContext for a non-existent merchant', function (): void {
    expect(fn () => (new ProbeTenantJob(999999))->handle())
        ->toThrow(MissingTenantContext::class);
});

it('binds a branch-scoped context when a branch id is provided', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $job = new class($merchant->id, $branch->id) extends TenantAwareJob
    {
        public ?bool $branchScoped = null;

        protected function handleWithinTenant(): void
        {
            $this->branchScoped = app(TenantContext::class)->isBranchScoped();
        }
    };

    $job->handle();

    expect($job->branchScoped)->toBeTrue();
});
