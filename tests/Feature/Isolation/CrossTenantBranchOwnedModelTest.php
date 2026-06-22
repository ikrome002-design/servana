<?php

declare(strict_types=1);

use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('tenancy', 'isolation');

/*
 | Cross-tenant isolation for branch-owned models (Plan §8.2; ADR-002; R5). With
 | a direct merchant_id + MerchantScope, a branch-owned model is constrained to
 | the resolved merchant, auto-fills merchant_id on create, and refuses to write
 | with no tenant context.
 */

function bindMerchant(MerchantUser $membership): void
{
    /** @var TenantContext $context */
    $context = app(TenantContext::class);
    $context->reset();
    $merchant = $membership->merchant;
    expect($merchant)->not->toBeNull();
    $context->setMerchant($merchant, $membership);
}

it('constrains a branch-owned model to the resolved merchant', function (): void {
    [, $merchantA, $membershipA] = activeAdmin();
    [, $merchantB] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchantA->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchantB->id]);

    BranchDayRecord::factory()->create(['branch_id' => $branchA->id, 'business_date' => '2026-06-01']);
    BranchDayRecord::factory()->create(['branch_id' => $branchB->id, 'business_date' => '2026-06-01']);

    bindMerchant($membershipA);

    expect(BranchDayRecord::query()->count())->toBe(1)
        ->and(BranchDayRecord::query()->first()->merchant_id)->toBe($merchantA->id);

    app(TenantContext::class)->reset();
});

it('auto-fills merchant_id from context on create', function (): void {
    [, $merchant, $membership] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    bindMerchant($membership);

    $record = BranchDayRecord::query()->create([
        'branch_id' => $branch->id,
        'business_date' => '2026-06-02',
        'status' => 'closed',
    ]);

    expect($record->merchant_id)->toBe($merchant->id);

    app(TenantContext::class)->reset();
});

it('refuses to create a branch-owned row with no tenant context', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    app(TenantContext::class)->reset();

    expect(fn () => BranchDayRecord::query()->create([
        'branch_id' => $branch->id,
        'business_date' => '2026-06-03',
        'status' => 'closed',
    ]))->toThrow(MissingTenantContext::class);
});
