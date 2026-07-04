<?php

declare(strict_types=1);

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Receipts\Models\ReceiptNumberSequence;
use App\Domain\Receipts\Services\ReceiptIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class)->group('payments', 'receipts');

/** Record + validate a fresh single-cash group against a same-merchant invoice. */
function issueOneReceipt(array $scn, int $amount = 100000): int
{
    $invoice = Invoice::factory()->issued($amount)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'subtotal_minor' => $amount,
        'total_minor' => $amount,
    ]);

    $groupUlid = (string) recordPaymentGroup($scn['frontOffice'], $invoice->ulid, [cashComponent($amount)])
        ->assertCreated()->json('data.id');

    return (int) validatePaymentGroup($scn['finance'], $groupUlid)
        ->assertCreated()->json('data.receipt.receipt_number');
}

it('allocates gap-free, strictly sequential receipt numbers per merchant', function (): void {
    Queue::fake();
    $scn = paymentScenario(100000);

    $n1 = issueOneReceipt($scn);
    $n2 = issueOneReceipt($scn);
    $n3 = issueOneReceipt($scn);

    expect([$n1, $n2, $n3])->toBe([1, 2, 3]);
    // The sequence counter is now at 4 (next value), never re-using a number.
    $sequence = ReceiptNumberSequence::query()->where('merchant_id', $scn['merchant']->id)->firstOrFail();
    expect($sequence->next_value)->toBe(4);
});

it('consumes no receipt number when a validation rolls back (no gap)', function (): void {
    Queue::fake();
    $scn = paymentScenario(100000);

    $n1 = issueOneReceipt($scn);
    expect($n1)->toBe(1);

    // A validation that rolls back mid-transaction must NOT advance the number.
    app()->bind(ReceiptIssuer::class, fn (): ReceiptIssuer => new class extends ReceiptIssuer
    {
        public function __construct() {}

        public function issueOriginal(Invoice $invoice, PaymentValidationEvent $event, array $components): Receipt
        {
            throw new RuntimeException('forced receipt failure');
        }
    });

    $badInvoice = Invoice::factory()->issued(100000)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'subtotal_minor' => 100000,
        'total_minor' => 100000,
    ]);
    $badGroup = (string) recordPaymentGroup($scn['frontOffice'], $badInvoice->ulid, [cashComponent(100000)])
        ->assertCreated()->json('data.id');
    validatePaymentGroup($scn['finance'], $badGroup)->assertStatus(500);

    // Restore the real issuer; the next receipt is 2 (NOT 3 — no gap).
    app()->forgetInstance(ReceiptIssuer::class);
    app()->offsetUnset(ReceiptIssuer::class);

    $n2 = issueOneReceipt($scn);
    expect($n2)->toBe(2);
});
