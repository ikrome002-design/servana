<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Actions\RecordCompensationAdjustment;
use App\Domain\Compensation\Actions\ReverseSalaryAccrual;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-commission');

/*
 | Phase 20G compensation adjustments (Plan §60/§61; §10.3). A Finance manual adjustment is additive
 | and high-severity audited; a salary reversal mirrors the commission path (exact-negative or a
 | paid adjustment). No payout run is ever created.
 */

it('records a manual Finance adjustment (additive, negative allowed) with a high-severity audit', function (): void {
    $branch = MerchantBranch::factory()->create();
    $staff = StaffProfile::factory()->create(['merchant_id' => $branch->merchant_id, 'primary_branch_id' => $branch->id]);
    $approver = User::factory()->create();

    $adjustment = app(RecordCompensationAdjustment::class)->manual(
        $staff, (int) $branch->id, -100000, 'KES', 'Correction for an over-credited commission.', null, $approver,
    );

    expect($adjustment->adjustment_type->value)->toBe('manual')
        ->and($adjustment->amount_minor)->toBe(-100000)
        ->and($adjustment->approved_by)->toBe($approver->id);

    $audit = AuditLog::query()->where('action', 'compensation.adjustment.created')->firstOrFail();
    expect($audit->severity->value)->toBe('high');
});

it('reverses an unpaid salary accrual with an exact-negative row and marks the original reversed', function (): void {
    $accrual = SalaryLedgerEntry::factory()->create(['amount_minor' => 5000000, 'status' => 'pending']);

    $reversal = app(ReverseSalaryAccrual::class)->handle($accrual);

    expect($reversal)->toBeInstanceOf(SalaryLedgerEntry::class)
        ->and($reversal->amount_minor)->toBe(-5000000)
        ->and($reversal->source_entry_id)->toBe($accrual->id);
    $original = SalaryLedgerEntry::query()->whereKey($accrual->id)->firstOrFail();
    expect($original->status->value)->toBe('reversed')->and($original->amount_minor)->toBe(5000000);
});

it('creates a negative adjustment for an already-paid salary accrual (paid history preserved)', function (): void {
    $paid = SalaryLedgerEntry::factory()->create(['amount_minor' => 5000000, 'status' => 'paid']);

    $result = app(ReverseSalaryAccrual::class)->handle($paid);

    expect($result)->toBeInstanceOf(CompensationAdjustment::class)
        ->and($result->amount_minor)->toBe(-5000000)
        ->and($result->adjustment_type->value)->toBe('paid_salary_reversal');
    expect($paid->fresh()->status->value)->toBe('paid');
});
