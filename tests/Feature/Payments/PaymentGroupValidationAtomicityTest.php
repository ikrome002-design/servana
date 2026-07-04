<?php

declare(strict_types=1);

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Receipts\Models\ReceiptNumberSequence;
use App\Domain\Receipts\Services\ReceiptIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('payments', 'payment-validation');

/**
 * A ReceiptIssuer double that always throws — used to force a side-effect failure
 * AFTER the validation event / components / invoice have been mutated, proving the
 * whole transaction rolls back (Gate B / atomicity).
 */
function throwingReceiptIssuer(): void
{
    app()->bind(ReceiptIssuer::class, fn (): ReceiptIssuer => new class extends ReceiptIssuer
    {
        public function __construct()
        {
            // Bypass the parent constructor's dependency — this double never issues.
        }

        public function issueOriginal(Invoice $invoice, PaymentValidationEvent $event, array $components): Receipt
        {
            throw new RuntimeException('forced receipt failure');
        }
    });
}

it('rolls back the entire validation when a side-effect fails: no event, no receipt, no number, invoice unchanged', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    throwingReceiptIssuer();

    // The forced failure surfaces as a 500 (server error); the point is the rollback.
    validatePaymentGroup($scn['finance'], $groupUlid)->assertStatus(500);

    // Nothing durable was written.
    expect(PaymentValidationEvent::query()->count())->toBe(0)
        ->and(Receipt::query()->count())->toBe(0)
        // The receipt number sequence was not advanced (rollback consumes no number).
        ->and(ReceiptNumberSequence::query()->count())->toBe(0);

    // The group is still pending_validation; components still pending; invoice unchanged.
    $group = PaymentRecordingGroup::query()->firstOrFail();
    expect($group->status)->toBe(PaymentRecordingGroupStatus::PendingValidation)
        ->and($group->validated_at)->toBeNull();

    $component = PaymentRecord::query()->firstOrFail();
    expect($component->status)->toBe(PaymentRecordStatus::PendingValidation)
        ->and($component->validated_amount_minor)->toBeNull();

    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->validated_paid_minor)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued);
});

it('does not leave the invoice paid while a required receipt is absent', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    throwingReceiptIssuer();
    validatePaymentGroup($scn['finance'], $groupUlid)->assertStatus(500);

    // The invariant: an invoice is never `paid`/`partially_paid` without its receipt.
    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->status)->not->toBe(InvoiceStatus::Paid)
        ->and($invoice->status)->not->toBe(InvoiceStatus::PartiallyPaid);
    expect(Receipt::query()->count())->toBe(0);
});
