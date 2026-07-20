<?php

declare(strict_types=1);

use App\Domain\Compensation\Actions\ReverseCommissionEntry;
use App\Domain\Compensation\Enums\CommissionReversalReason;
use App\Domain\Compensation\Exceptions\CompensationLedgerException;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-commission');

/*
 | Phase 20G commission reversal + already-paid adjustment (Plan §61; §10). The original monetary
 | fact is never recomputed or edited; a not-yet-paid original yields an exact-negative append-only
 | reversal, and a PAID original yields a negative compensation_adjustment (paid history preserved).
 */

it('reverses an unpaid earned entry with an exact-negative append-only row', function (): void {
    $earned = CommissionLedgerEntry::factory()->create(['amount_minor' => 50000, 'status' => 'earned']);

    $reversal = app(ReverseCommissionEntry::class)->handle($earned, CommissionReversalReason::InvoiceVoided);

    expect($reversal)->toBeInstanceOf(CommissionLedgerEntry::class)
        ->and($reversal->amount_minor)->toBe(-50000)
        ->and($reversal->source_entry_id)->toBe($earned->id)
        ->and($reversal->reversal_reason->value)->toBe('invoice_voided');

    $original = CommissionLedgerEntry::query()->whereKey($earned->id)->firstOrFail();
    expect($original->status->value)->toBe('reversed')->and($original->amount_minor)->toBe(50000);
});

it('is idempotent: a second reversal returns the existing row and creates no duplicate', function (): void {
    $earned = CommissionLedgerEntry::factory()->create(['amount_minor' => 50000, 'status' => 'earned']);

    $first = app(ReverseCommissionEntry::class)->handle($earned, CommissionReversalReason::RefundFinalized);
    $second = app(ReverseCommissionEntry::class)->handle($earned->fresh(), CommissionReversalReason::RefundFinalized);

    expect($second->id)->toBe($first->id);
    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(1);
});

it('creates a negative adjustment (not a ledger reversal) for an already-paid entry, preserving paid history', function (): void {
    $paid = CommissionLedgerEntry::factory()->create(['amount_minor' => 50000, 'status' => 'paid']);

    $result = app(ReverseCommissionEntry::class)->handle($paid, CommissionReversalReason::PaymentReversed);

    expect($result)->toBeInstanceOf(CompensationAdjustment::class)
        ->and($result->amount_minor)->toBe(-50000)
        ->and($result->adjustment_type->value)->toBe('paid_commission_reversal')
        ->and($result->source_commission_ledger_id)->toBe($paid->id);

    // No ledger reversal row; the paid original is untouched.
    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(0);
    $original = CommissionLedgerEntry::query()->whereKey($paid->id)->firstOrFail();
    expect($original->status->value)->toBe('paid')->and($original->amount_minor)->toBe(50000);
});

it('is idempotent for an already-paid reversal (one adjustment per paid source)', function (): void {
    $paid = CommissionLedgerEntry::factory()->create(['amount_minor' => 50000, 'status' => 'paid']);

    app(ReverseCommissionEntry::class)->handle($paid, CommissionReversalReason::RefundFinalized);
    app(ReverseCommissionEntry::class)->handle($paid, CommissionReversalReason::RefundFinalized);

    expect(CompensationAdjustment::query()->where('source_commission_ledger_id', $paid->id)->count())->toBe(1);
});

it('refuses to reverse a non-earned entry', function (): void {
    $earned = CommissionLedgerEntry::factory()->create(['amount_minor' => 50000, 'status' => 'earned']);
    $reversal = app(ReverseCommissionEntry::class)->handle($earned, CommissionReversalReason::InvoiceVoided);

    expect(fn () => app(ReverseCommissionEntry::class)->handle($reversal, CommissionReversalReason::Correction))
        ->toThrow(CompensationLedgerException::class);
});
