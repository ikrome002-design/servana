<?php

declare(strict_types=1);

use App\Domain\Compensation\Actions\CreateEarningsQuery;
use App\Domain\Compensation\Actions\RespondToEarningsQuery;
use App\Domain\Compensation\Enums\EarningsQueryAssignedRole;
use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Enums\EarningsQuerySubjectType;
use App\Domain\Compensation\Enums\EarningsQueryType;
use App\Domain\Compensation\Exceptions\CompensationScopeException;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20h', 'phase20h-earnings');

/*
 | Phase 20H earnings queries (Plan §63; §H12). Own-scope creation with subject validation; Finance
 | responds; monetary correction ONLY through a compensation adjustment (never a ledger edit).
 */

it('lets personnel create an own-scope query against an own commission row', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $commission = earnedCommission($branch, $staff, 50000);

    $query = app(CreateEarningsQuery::class)->handle(
        $staff, EarningsQuerySubjectType::CommissionLedger, $commission->ulid,
        EarningsQueryType::CommissionDisagreement, 'This looks too low.',
    );

    expect($query->status)->toBe(EarningsQueryStatus::Open)
        ->and($query->staff_profile_id)->toBe($staff->id)
        ->and($query->subject_id)->toBe($commission->id)
        ->and($query->assigned_role)->toBe(EarningsQueryAssignedRole::Finance);
});

it('routes a statement request to HR', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $commission = earnedCommission($branch, $staff, 50000);

    $query = app(CreateEarningsQuery::class)->handle(
        $staff, EarningsQuerySubjectType::CommissionLedger, $commission->ulid,
        EarningsQueryType::StatementRequest, 'Where is my statement?',
    );

    expect($query->assigned_role)->toBe(EarningsQueryAssignedRole::Hr);
});

it('rejects a query against another staff commission row (no existence leak)', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    [$otherBranch, $otherStaff] = payoutBranchStaff();
    $foreign = earnedCommission($otherBranch, $otherStaff, 50000);

    expect(fn () => app(CreateEarningsQuery::class)->handle(
        $staff, EarningsQuerySubjectType::CommissionLedger, $foreign->ulid,
        EarningsQueryType::CommissionDisagreement, 'Not mine.',
    ))->toThrow(CompensationScopeException::class);
});

it('resolves a query with a monetary correction through a compensation adjustment only', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $commission = earnedCommission($branch, $staff, 50000);
    $query = app(CreateEarningsQuery::class)->handle(
        $staff, EarningsQuerySubjectType::CommissionLedger, $commission->ulid,
        EarningsQueryType::CommissionDisagreement, 'Underpaid by 1000.',
    );

    $resolved = app(RespondToEarningsQuery::class)->handle(
        $query, User::factory()->create(), EarningsQueryStatus::Resolved, 'Correcting +1000.',
        ['amount_minor' => 1000, 'currency' => 'KES', 'reason' => 'Earnings query correction.'],
    );

    expect($resolved->status)->toBe(EarningsQueryStatus::Resolved)
        ->and($resolved->resolved_adjustment_id)->not->toBeNull();
    // The correction is a NEW adjustment; the source ledger amount is untouched.
    expect(CompensationAdjustment::query()->where('staff_profile_id', $staff->id)->count())->toBe(1);
    expect($commission->refresh()->amount_minor)->toBe(50000);
});

it('rejects a query without creating any adjustment', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $commission = earnedCommission($branch, $staff, 50000);
    $query = app(CreateEarningsQuery::class)->handle(
        $staff, EarningsQuerySubjectType::CommissionLedger, $commission->ulid,
        EarningsQueryType::CommissionDisagreement, 'Please review.',
    );

    $rejected = app(RespondToEarningsQuery::class)->handle(
        $query, User::factory()->create(), EarningsQueryStatus::Rejected, 'Calculated correctly.',
    );

    expect($rejected->status)->toBe(EarningsQueryStatus::Rejected);
    expect(CompensationAdjustment::query()->where('staff_profile_id', $staff->id)->count())->toBe(0);
});

it('cannot respond to an already-resolved query (no duplicate correction on replay)', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $commission = earnedCommission($branch, $staff, 50000);
    $query = app(CreateEarningsQuery::class)->handle(
        $staff, EarningsQuerySubjectType::CommissionLedger, $commission->ulid,
        EarningsQueryType::CommissionDisagreement, 'Underpaid.',
    );
    app(RespondToEarningsQuery::class)->handle(
        $query, User::factory()->create(), EarningsQueryStatus::Resolved, 'Fixed.',
        ['amount_minor' => 1000, 'currency' => 'KES', 'reason' => 'Correction.'],
    );

    expect(fn () => app(RespondToEarningsQuery::class)->handle(
        $query->refresh(), User::factory()->create(), EarningsQueryStatus::Resolved, 'Again.',
        ['amount_minor' => 1000, 'currency' => 'KES', 'reason' => 'Correction.'],
    ))->toThrow(CompensationStateException::class);

    expect(CompensationAdjustment::query()->where('staff_profile_id', $staff->id)->count())->toBe(1);
});
