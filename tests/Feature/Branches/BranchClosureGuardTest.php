<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\BranchStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Branches\Services\BranchClosureGuard;
use App\Domain\Branches\Services\BranchDebtGate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('branches');

it('archives a branch with no live operational records', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/branches/{$branch->ulid}/archive", ['reason' => 'Relocating'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'archived');

    expect($branch->fresh()->status)->toBe(BranchStatus::Archived);
});

it('blocks archival while a branch day is unclosed', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    BranchDayRecord::factory()->open()->create(['branch_id' => $branch->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/branches/{$branch->ulid}/archive")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'branch_closure_blocked')
        ->assertJsonPath('error.meta.blockers', ['unclosed_branch_day']);

    expect($branch->fresh()->status)->toBe(BranchStatus::Active);
});

it('blocks archival while a cash-up discrepancy is unresolved', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    BranchCashUp::factory()->unresolvedDiscrepancy()->create(['branch_id' => $branch->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/branches/{$branch->ulid}/archive")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'branch_closure_blocked')
        ->assertJsonPath('error.meta.blockers', ['unresolved_cash_up_discrepancy']);
});

it('reports no blockers for a clean branch and consults the debt gate', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $guard = app(BranchClosureGuard::class);

    expect($guard->blockers($branch))->toBe([])
        ->and($guard->canClose($branch))->toBeTrue();

    // The Phase 16 debt gate currently reports zero (stub) and is wired in.
    expect(app(BranchDebtGate::class)->outstandingDebtMinor($branch))->toBe(0)
        ->and(app(BranchDebtGate::class)->hasOutstandingDebt($branch))->toBeFalse();
});
