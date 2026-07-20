<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CommissionHandoffConsumer;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Actions\ExecuteInvoiceVoid;
use App\Domain\Invoicing\Actions\RequestInvoiceVoid;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Payments\Actions\RejectPaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-commission');

/*
 | Phase 20G Increment 4 — non-refund source seams (product-owner resolution 2026-07-18).
 |
 | INVOICE VOID: ExecuteInvoiceVoid does NOT reverse or invalidate the validated payment allocation
 | (validated_paid_minor and the payment records are untouched); the authoritative mechanism that
 | removes a validated allocation is a REFUND. So a void must NOT reverse earned commission merely
 | because the invoice status changed, and writes no commission reversal handoff.
 |
 | PAYMENT CORRECTION / REJECTION: reject/correction require `pending_validation` and therefore occur
 | BEFORE commission is earned (commission is earned only at Finance validation). There is no
 | canonical post-validation payment-reversal event; the path can never reverse commission.
 */

/** Build a validated invoice with exactly one earned commission row via the real earning chain. */
function seamEarnedInvoice(): array
{
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
        'subtotal_minor' => 500000, 'discount_minor' => 0, 'total_minor' => 500000, 'validated_paid_minor' => 500000,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id, 'staff_profile_id' => $staff->id, 'eligible_for_commission' => true,
        'unit_price_minor' => 500000, 'line_total_minor' => 500000, 'quantity' => 1, 'currency' => 'KES',
    ]);

    $group = PaymentRecordingGroup::factory()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'invoice_id' => $invoice->id, 'total_amount_minor' => 500000,
    ]);
    $component = PaymentRecord::factory()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'invoice_id' => $invoice->id,
        'payment_recording_group_id' => $group->id, 'amount_minor' => 500000,
        'validated_amount_minor' => 500000, 'currency' => 'KES',
    ]);
    $event = PaymentValidationEvent::factory()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'invoice_id' => $invoice->id,
        'payment_recording_group_id' => $group->id, 'decision' => 'validated', 'validated_amount_minor' => 500000,
    ]);
    CommissionHandoffEvent::factory()->create([
        'merchant_id' => $m, 'branch_id' => $b, 'kind' => 'validated_allocation',
        'payment_validation_event_id' => $event->id, 'payment_record_id' => $component->id,
        'invoice_id' => $invoice->id, 'amount_minor' => 500000, 'currency' => 'KES',
    ]);
    app(CommissionHandoffConsumer::class)->process();

    return compact('invoice', 'component', 'event');
}

it('invoice void does not reverse earned commission and writes no reversal handoff', function (): void {
    $s = seamEarnedInvoice();
    $earned = CommissionLedgerEntry::query()->where('entry_type', 'earned')->firstOrFail();
    expect($earned->amount_minor)->toBe(50000);

    /** @var Invoice $invoice */
    $invoice = Invoice::query()->whereKey($s['invoice']->id)->firstOrFail();
    $actor = User::factory()->create();

    app(RequestInvoiceVoid::class)->handle($invoice, $actor, 'Duplicate invoice raised in error.');
    app(ExecuteInvoiceVoid::class)->handle($invoice->fresh(), $actor);

    expect($invoice->fresh()->status->value)->toBe('voided');
    // The void produced no commission reversal handoff; the consumer reverses nothing.
    expect(CommissionHandoffEvent::query()->where('kind', 'reversal')->count())->toBe(0);
    app(CommissionHandoffConsumer::class)->process();
    expect(CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(0);
    expect(CommissionLedgerEntry::query()->whereKey($earned->id)->firstOrFail()->status->value)->toBe('earned');
});

it('a pre-validation payment rejection earns and reverses no commission', function (): void {
    $group = PaymentRecordingGroup::factory()->pendingValidation()->create(['maker_user_id' => User::factory()]);
    PaymentRecord::factory()->create([
        'merchant_id' => $group->merchant_id, 'branch_id' => $group->branch_id,
        'invoice_id' => $group->invoice_id, 'payment_recording_group_id' => $group->id,
        'amount_minor' => $group->total_amount_minor, 'currency' => $group->currency,
    ]);

    $checker = User::factory()->create();
    app(RejectPaymentRecordingGroup::class)->handle($group->fresh(), $checker, 'Reference could not be verified.');

    // No commission handoff of any kind and no commission ledger effect from a pre-validation rejection.
    expect(CommissionHandoffEvent::query()->count())->toBe(0);
    expect(CommissionLedgerEntry::query()->count())->toBe(0);
    app(CommissionHandoffConsumer::class)->process();
    expect(CommissionLedgerEntry::query()->count())->toBe(0);
});
