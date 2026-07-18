<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CommissionHandoffConsumer;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Refunds\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-commission');

/*
 | Phase 20G Increment 4 — commission reversal driven by the refund-finalization handoff under the
 | AUTHORITATIVE exact-negative rule (ADR-005; Plan §61; product-owner resolution 2026-07-18).
 |
 | A reversal is ALWAYS the exact negative of a whole earned row (never a recomputed fraction), and
 | there is at most one reversal per original. Commission is earned per validation event across all
 | items with NO immutable item-level refund attribution, so earned rows are reversed ONLY once the
 | ENTIRE validated allocation has been refunded (cumulative finalized refunds = validated amount).
 | A partial refund is a valid NO-EFFECT source event; an impossible over-refund fails CLOSED.
 */

/**
 * Build a validated single-item scenario over N payment components and (optionally) consume the
 * earning handoff so exactly one earned commission row exists. Percentage rule on service_price.
 *
 * @param  list<int>  $componentAmounts
 * @return array<string,mixed>
 */
function reversalScenario(array $componentAmounts = [500000], bool $consumeEarning = true, bool $withValidationHandoff = true): array
{
    $total = array_sum($componentAmounts);

    $branch = MerchantBranch::factory()->create();
    $m = (int) $branch->merchant_id;
    $b = (int) $branch->id;

    $staff = StaffProfile::factory()->create(['merchant_id' => $m, 'primary_branch_id' => $b]);

    $rule = CommissionRule::factory()->active()->percentage(1000)->allServices()
        ->state(['merchant_id' => $m, 'branch_id' => $b, 'calculation_basis' => 'service_price'])->create();

    PersonnelCompensationPlan::factory()->active()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'staff_profile_id' => $staff->id,
        'commission_rule_id' => $rule->id, 'compensation_model' => 'commission_only',
        'salary_amount_minor' => null, 'salary_currency' => null, 'salary_period' => null,
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);

    $invoice = Invoice::factory()->issued()->create([
        'merchant_id' => $m, 'branch_id' => $b,
        'subtotal_minor' => $total, 'discount_minor' => 0, 'total_minor' => $total, 'validated_paid_minor' => $total,
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id, 'staff_profile_id' => $staff->id, 'eligible_for_commission' => true,
        'unit_price_minor' => $total, 'line_total_minor' => $total, 'quantity' => 1, 'currency' => 'KES',
    ]);

    $group = PaymentRecordingGroup::factory()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'invoice_id' => $invoice->id,
        'total_amount_minor' => $total,
    ]);

    $components = [];
    foreach ($componentAmounts as $amount) {
        $components[] = PaymentRecord::factory()->create([
            'merchant_id' => $m, 'branch_id' => $b, 'invoice_id' => $invoice->id,
            'payment_recording_group_id' => $group->id, 'amount_minor' => $amount,
            'validated_amount_minor' => $amount, 'currency' => 'KES',
        ]);
    }

    $event = PaymentValidationEvent::factory()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'invoice_id' => $invoice->id,
        'payment_recording_group_id' => $group->id, 'decision' => 'validated', 'validated_amount_minor' => $total,
    ]);

    if ($withValidationHandoff) {
        CommissionHandoffEvent::factory()->create([
            'merchant_id' => $m, 'branch_id' => $b, 'kind' => 'validated_allocation',
            'payment_validation_event_id' => $event->id, 'payment_record_id' => $components[0]->id,
            'invoice_id' => $invoice->id, 'amount_minor' => $total, 'currency' => 'KES',
        ]);
        if ($consumeEarning) {
            app(CommissionHandoffConsumer::class)->process();
        }
    }

    return compact('branch', 'm', 'b', 'staff', 'rule', 'invoice', 'group', 'components', 'event', 'total');
}

/** Finalize a refund against a component and write the matching reversal handoff (as FinalizeRefund does). */
function finalizeRefundHandoff(array $s, PaymentRecord $component, int $amount): CommissionHandoffEvent
{
    $refund = Refund::factory()->finalized()->create([
        'merchant_id' => $s['m'], 'branch_id' => $s['b'], 'invoice_id' => $s['invoice']->id,
        'payment_record_id' => $component->id, 'amount_minor' => $amount,
    ]);

    return CommissionHandoffEvent::factory()->create([
        'merchant_id' => $s['m'], 'branch_id' => $s['b'], 'kind' => 'reversal',
        'refund_id' => $refund->id, 'payment_record_id' => $component->id, 'invoice_id' => $s['invoice']->id,
        'payment_validation_event_id' => null, 'amount_minor' => $amount, 'currency' => 'KES',
    ]);
}

function consume(): array
{
    return app(CommissionHandoffConsumer::class)->process();
}

it('full refund reverses the earned row exactly once (exact negative, original unchanged)', function (): void {
    $s = reversalScenario([500000]);
    $earned = CommissionLedgerEntry::query()->where('entry_type', 'earned')->firstOrFail();
    expect($earned->amount_minor)->toBe(50000);

    finalizeRefundHandoff($s, $s['components'][0], 500000);
    $summary = consume();

    expect($summary['reversal_rows'])->toBe(1)->and($summary['deferred_partial'])->toBe(0);
    $reversal = CommissionLedgerEntry::query()->where('entry_type', 'reversal')->firstOrFail();
    expect($reversal->amount_minor)->toBe(-50000)                    // exact negative
        ->and($reversal->source_entry_id)->toBe($earned->id)
        ->and($reversal->reversal_reason->value)->toBe('refund_finalized');
    $original = CommissionLedgerEntry::query()->whereKey($earned->id)->firstOrFail();
    expect($original->status->value)->toBe('reversed')->and($original->amount_minor)->toBe(50000); // unchanged
});

it('partial refund below the validated allocation creates NO reversal (no fraction, no over-reversal)', function (): void {
    $s = reversalScenario([500000]);

    finalizeRefundHandoff($s, $s['components'][0], 200000); // 200000 < 500000
    $summary = consume();

    expect($summary['reversal_rows'])->toBe(0)->and($summary['deferred_partial'])->toBe(1);
    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(0);
    // The original earned row is untouched and the partial handoff is consumed (re-evaluable).
    expect(CommissionLedgerEntry::query()->where('entry_type', 'earned')->firstOrFail()->status->value)->toBe('earned');
    expect(CommissionHandoffEvent::query()->where('kind', 'reversal')->whereNull('consumed_at')->count())->toBe(0);
});

it('multiple partial refunds still below the total create NO reversal', function (): void {
    $s = reversalScenario([500000]);

    finalizeRefundHandoff($s, $s['components'][0], 150000);
    finalizeRefundHandoff($s, $s['components'][0], 150000); // cumulative 300000 < 500000
    $summary = consume();

    expect($summary['reversal_rows'])->toBe(0)->and($summary['deferred_partial'])->toBe(2);
    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(0);
});

it('the refund that completes the full validated allocation reverses exactly once', function (): void {
    // Two components 250000 + 250000 = 500000. Fully refunding both completes the allocation.
    $s = reversalScenario([250000, 250000]);

    finalizeRefundHandoff($s, $s['components'][0], 250000); // cumulative 250000 < 500000 → deferred
    $first = consume();
    expect($first['reversal_rows'])->toBe(0)->and($first['deferred_partial'])->toBe(1);

    finalizeRefundHandoff($s, $s['components'][1], 250000); // cumulative 500000 = 500000 → reverse
    $second = consume();
    expect($second['reversal_rows'])->toBe(1);

    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(1);
    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->value('amount_minor'))->toBe(-50000);
});

it('replaying a full-refund handoff creates no duplicate reversal', function (): void {
    $s = reversalScenario([500000]);
    finalizeRefundHandoff($s, $s['components'][0], 500000);

    consume();
    CommissionHandoffEvent::query()->where('kind', 'reversal')->update(['consumed_at' => null]); // force replay
    consume();

    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(1);
});

it('is idempotent at the database: a second reversal row for the same original is rejected', function (): void {
    $s = reversalScenario([500000]);
    finalizeRefundHandoff($s, $s['components'][0], 500000);
    consume();
    $reversal = CommissionLedgerEntry::query()->where('entry_type', 'reversal')->firstOrFail();

    // Attempt to insert a second reversal referencing the same original → unique index violation.
    expect(fn () => CommissionLedgerEntry::query()->create(array_merge(
        $reversal->only([
            'merchant_id', 'branch_id', 'staff_profile_id', 'compensation_plan_id', 'commission_rule_id',
            'service_session_id', 'invoice_id', 'invoice_item_id', 'payment_record_id',
            'payment_validation_event_id', 'source_entry_id', 'calculation_basis_minor', 'rate_basis_points',
            'fixed_rate_minor', 'currency',
        ]),
        ['entry_type' => 'reversal', 'reversal_reason' => 'refund_finalized', 'amount_minor' => -50000, 'status' => 'earned'],
    )))->toThrow(QueryException::class);
});

it('fails closed when cumulative finalized refunds exceed the validated allocation', function (): void {
    $s = reversalScenario([500000]);

    // Two finalized refunds summing 600000 > 500000 (impossible via FinalizeRefund; asserted defensively).
    finalizeRefundHandoff($s, $s['components'][0], 500000);
    finalizeRefundHandoff($s, $s['components'][0], 100000);
    $summary = consume();

    expect($summary['failed'])->toBeGreaterThanOrEqual(1);
    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(0); // never over-reverse
    // A retryable failure signal is written; no commission-reversed success audit.
    expect(AuditLog::query()->where('action', 'compensation.handoff.failed')->count())->toBeGreaterThanOrEqual(1);
    expect(AuditLog::query()->where('action', 'compensation.commission.reversed')->count())->toBe(0);
    // The offending handoffs remain retryable (not consumed).
    expect(CommissionHandoffEvent::query()->where('kind', 'reversal')->whereNull('consumed_at')->count())->toBeGreaterThanOrEqual(1);
});

it('defers a reversal whose original earning has not been consumed yet (causal ordering, retryable)', function (): void {
    // No validation handoff consumed → the earning does not exist yet.
    $s = reversalScenario([500000], consumeEarning: false, withValidationHandoff: false);
    finalizeRefundHandoff($s, $s['components'][0], 500000);

    $summary = consume();

    expect($summary['failed'])->toBe(1)->and($summary['reversal_rows'])->toBe(0);
    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(0);
    // Retryable: the reversal handoff stays unconsumed.
    expect(CommissionHandoffEvent::query()->where('kind', 'reversal')->whereNull('consumed_at')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'compensation.handoff.failed')
        ->where('context->error_code', 'commission_original_not_yet_earned')->count())->toBe(1);
});

it('reverses no more than the earned rows even when the consumer runs repeatedly (single result)', function (): void {
    $s = reversalScenario([500000]);
    finalizeRefundHandoff($s, $s['components'][0], 500000);

    consume();
    consume();
    consume();

    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(1);
    expect((int) CommissionLedgerEntry::query()->sum('amount_minor'))->toBe(0); // earned + reversal net to zero
});

it('reverses an already-PAID original via a negative adjustment, never mutating paid history', function (): void {
    $s = reversalScenario([500000]);
    // Mark the earned original paid (a future Phase 20H payout lifecycle state).
    $earned = CommissionLedgerEntry::query()->where('entry_type', 'earned')->firstOrFail();
    $earned->forceFill(['status' => 'paid'])->save();

    finalizeRefundHandoff($s, $s['components'][0], 500000);
    consume();

    // No ledger reversal row; a negative compensation_adjustment offsets the paid original instead.
    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(0);
    expect(CompensationAdjustment::query()
        ->where('source_commission_ledger_id', $earned->id)
        ->where('amount_minor', -50000)->count())->toBe(1);
    expect(CommissionLedgerEntry::query()->whereKey($earned->id)->firstOrFail()->status->value)->toBe('paid'); // unchanged
});
