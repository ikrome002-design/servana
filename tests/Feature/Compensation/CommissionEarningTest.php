<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CommissionRuleService;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CommissionHandoffConsumer;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Refunds\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-commission');

/*
 | Phase 20G commission earning at Finance validation via the durable commission_handoff_events
 | outbox (Plan §61; G3/G4/G5). Proves basis mappings, the fixed cap, model/applicability
 | eligibility, largest-remainder allocation, idempotency, and atomic consumed_at.
 */

/**
 * Build a validated-payment scenario and return its pieces. $ruleState mutates the rule factory.
 *
 * @return array<string,mixed>
 */
function commissionScenario(?callable $ruleState = null, array $itemOverrides = [], int $validatedAmount = 500000, int $invoiceDiscount = 0, CompensationModel $model = CompensationModel::CommissionOnly): array
{
    $branch = MerchantBranch::factory()->create();
    $m = (int) $branch->merchant_id;
    $b = (int) $branch->id;

    $staff = StaffProfile::factory()->create(['merchant_id' => $m, 'primary_branch_id' => $b]);

    $ruleFactory = CommissionRule::factory()->active()->state(['merchant_id' => $m, 'branch_id' => $b]);
    if ($ruleState !== null) {
        $ruleFactory = $ruleState($ruleFactory);
    }
    $rule = $ruleFactory->create();

    $plan = PersonnelCompensationPlan::factory()->active()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'staff_profile_id' => $staff->id,
        'commission_rule_id' => $model === CompensationModel::SalaryOnly ? null : $rule->id,
        'compensation_model' => $model,
        'salary_amount_minor' => $model === CompensationModel::CommissionOnly ? null : 5000000,
        'salary_currency' => $model === CompensationModel::CommissionOnly ? null : 'KES',
        'salary_period' => $model === CompensationModel::CommissionOnly ? null : 'monthly',
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);

    $invoice = Invoice::factory()->issued()->create([
        'merchant_id' => $m, 'branch_id' => $b,
        'subtotal_minor' => 500000, 'discount_minor' => $invoiceDiscount,
        'total_minor' => 500000, 'validated_paid_minor' => 0,
    ]);

    $item = InvoiceItem::factory()->create(array_merge([
        'invoice_id' => $invoice->id, 'staff_profile_id' => $staff->id, 'eligible_for_commission' => true,
        'unit_price_minor' => 500000, 'line_total_minor' => 500000, 'quantity' => 1, 'currency' => 'KES',
    ], $itemOverrides));

    $group = PaymentRecordingGroup::factory()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'invoice_id' => $invoice->id, 'total_amount_minor' => $validatedAmount,
    ]);
    $component = PaymentRecord::factory()->create([
        'payment_recording_group_id' => $group->id, 'amount_minor' => $validatedAmount,
        'validated_amount_minor' => $validatedAmount, 'currency' => 'KES',
    ]);
    $event = PaymentValidationEvent::factory()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'invoice_id' => $invoice->id,
        'payment_recording_group_id' => $group->id, 'validated_amount_minor' => $validatedAmount,
    ]);
    $handoff = CommissionHandoffEvent::factory()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'kind' => 'validated_allocation',
        'payment_validation_event_id' => $event->id, 'payment_record_id' => $component->id,
        'invoice_id' => $invoice->id, 'amount_minor' => $validatedAmount, 'currency' => 'KES',
    ]);

    return compact('branch', 'm', 'b', 'staff', 'rule', 'plan', 'invoice', 'item', 'group', 'component', 'event', 'handoff');
}

function consumeHandoffs(): array
{
    return app(CommissionHandoffConsumer::class)->process();
}

it('earns a percentage commission on service_price at Finance validation and stamps consumed_at', function (): void {
    $s = commissionScenario(fn ($f) => $f->percentage(1000)->allServices()->state(['calculation_basis' => 'service_price']));

    $summary = consumeHandoffs();

    expect($summary['earned_rows'])->toBe(1);
    $earned = CommissionLedgerEntry::query()->where('entry_type', 'earned')->firstOrFail();
    expect($earned->amount_minor)->toBe(50000) // 10% of KES 5,000.00
        ->and($earned->staff_profile_id)->toBe($s['staff']->id)
        ->and($earned->payment_validation_event_id)->toBe($s['event']->id);
    expect(CommissionHandoffEvent::query()->whereKey($s['handoff']->id)->value('consumed_at'))->not->toBeNull();
    expect(AuditLog::query()->where('action', 'compensation.commission.earned')->count())->toBe(1);
});

it('caps a fixed commission at the eligible validated allocation', function (): void {
    // Fixed KES 8,000.00 but only KES 5,000.00 validated → capped at 500000.
    commissionScenario(fn ($f) => $f->fixedAmount(800000)->allServices()->state(['calculation_basis' => 'paid_amount']));

    consumeHandoffs();

    expect(CommissionLedgerEntry::query()->where('entry_type', 'earned')->value('amount_minor'))->toBe(500000);
});

it('earns no commission for a salary_only plan', function (): void {
    commissionScenario(null, [], 500000, 0, CompensationModel::SalaryOnly);

    consumeHandoffs();

    expect(CommissionLedgerEntry::query()->count())->toBe(0);
});

it('does not earn for a selected_services rule when the item service is not a member', function (): void {
    commissionScenario(fn ($f) => $f->percentage(1000)->selectedServices());

    consumeHandoffs();

    // The item's service is not in the (empty) membership set → not eligible → no earned row.
    expect(CommissionLedgerEntry::query()->count())->toBe(0);
});

it('earns for a selected_services rule when the item service IS a member', function (): void {
    $s = commissionScenario(fn ($f) => $f->percentage(1000)->selectedServices()->state(['calculation_basis' => 'service_price']));

    // The rule was created active via INSERT with no membership; add the item's service as a member
    // (memberships are keyed by rule+service and the DB same-branch guard is satisfied). A membership
    // row on an already-active rule is only permitted by the guard while draft, so flip the rule to
    // draft, attach, then re-activate — mirroring the real supersede-to-a-new-draft config path.
    $rule = $s['rule'];
    $rule->forceFill(['status' => 'draft'])->save();
    CommissionRuleService::factory()->create([
        'merchant_id' => $s['m'], 'branch_id' => $s['b'], 'commission_rule_id' => $rule->id,
        'service_id' => $s['item']->service_id,
    ]);
    $rule->forceFill(['status' => 'active'])->save();

    consumeHandoffs();

    expect(CommissionLedgerEntry::query()->where('entry_type', 'earned')->value('amount_minor'))->toBe(50000);
});

it('includes the preferred-personnel fee in the basis exactly once when the rule opts in', function (): void {
    // service_price 500000 + preferred fee 100000 = basis 600000; 10% = 60000.
    commissionScenario(
        fn ($f) => $f->percentage(1000)->allServices()->includingPreferredPersonnelFee()->state(['calculation_basis' => 'service_price']),
        ['preferred_personnel_fee_minor' => 100000],
    );

    consumeHandoffs();

    expect(CommissionLedgerEntry::query()->where('entry_type', 'earned')->value('amount_minor'))->toBe(60000);
});

it('is idempotent: replaying the consumer creates no duplicate earned row', function (): void {
    commissionScenario(fn ($f) => $f->percentage(1000)->allServices()->state(['calculation_basis' => 'service_price']));

    consumeHandoffs();
    // Reset consumed_at to force a re-process attempt; the earned unique + skip logic must hold.
    CommissionHandoffEvent::query()->update(['consumed_at' => null]);
    consumeHandoffs();

    expect(CommissionLedgerEntry::query()->where('entry_type', 'earned')->count())->toBe(1);
});

it('allocates the validated amount across multiple eligible items by largest remainder, capped at the validation total', function (): void {
    // One validated payment of KES 5,000.00 over two items (line totals 3,000 + 2,000) for one staff.
    $s = commissionScenario(
        fn ($f) => $f->percentage(1000)->allServices()->state(['calculation_basis' => 'paid_amount']),
        ['line_total_minor' => 300000, 'unit_price_minor' => 300000],
    );
    // Second eligible item on the SAME invoice + staff (line total 2,000.00).
    InvoiceItem::factory()->create([
        'invoice_id' => $s['invoice']->id, 'merchant_id' => $s['m'], 'branch_id' => $s['b'],
        'staff_profile_id' => $s['staff']->id, 'eligible_for_commission' => true,
        'unit_price_minor' => 200000, 'line_total_minor' => 200000, 'quantity' => 1, 'currency' => 'KES',
    ]);

    consumeHandoffs();

    $earned = CommissionLedgerEntry::query()->where('entry_type', 'earned')->get();
    // paid_amount basis: item allocations 300000 + 200000 = 500000 (= validated); 10% => 30000 + 20000.
    expect($earned)->toHaveCount(2);
    expect((int) $earned->sum('amount_minor'))->toBe(50000);
    expect((int) $earned->sum('amount_minor'))->toBeLessThanOrEqual((int) $s['event']->validated_amount_minor);
});

it('reverses earned commission exactly on a refund-finalization handoff', function (): void {
    $s = commissionScenario(fn ($f) => $f->percentage(1000)->allServices()->state(['calculation_basis' => 'service_price']));
    consumeHandoffs();
    $earned = CommissionLedgerEntry::query()->where('entry_type', 'earned')->firstOrFail();

    // A reversal handoff only exists in production for a FINALIZED refund (FinalizeRefund writes it
    // in the same transaction that sets status=finalized). A full refund (amount == validated).
    $refund = Refund::factory()->finalized()->create([
        'merchant_id' => $s['m'], 'branch_id' => $s['b'], 'invoice_id' => $s['invoice']->id,
        'payment_record_id' => $s['component']->id, 'amount_minor' => 500000,
    ]);
    CommissionHandoffEvent::factory()->create([
        'merchant_id' => $s['m'], 'branch_id' => $s['b'], 'kind' => 'reversal',
        'refund_id' => $refund->id, 'payment_record_id' => $s['component']->id, 'invoice_id' => $s['invoice']->id,
        'payment_validation_event_id' => null, 'amount_minor' => 500000, 'currency' => 'KES',
    ]);

    consumeHandoffs();

    $reversal = CommissionLedgerEntry::query()->where('entry_type', 'reversal')->firstOrFail();
    expect($reversal->amount_minor)->toBe(-50000)
        ->and($reversal->source_entry_id)->toBe($earned->id)
        ->and($reversal->reversal_reason->value)->toBe('refund_finalized');
    // Original is marked reversed but its amount is UNCHANGED.
    $original = CommissionLedgerEntry::query()->whereKey($earned->id)->firstOrFail();
    expect($original->status->value)->toBe('reversed')->and($original->amount_minor)->toBe(50000);
});
