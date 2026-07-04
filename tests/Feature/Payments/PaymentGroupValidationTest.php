<?php

declare(strict_types=1);

use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use App\Domain\Receipts\Models\Receipt;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('payments', 'payment-validation');

// Fake the receipt-PDF outbox job for every case so the afterCommit dispatch never
// writes to real object storage (deterministic; no MinIO coupling under parallel load).
beforeEach(fn () => Queue::fake([GenerateReceiptPdf::class]));

it('lets Finance validate a whole group: one event, components validated, invoice paid, one receipt', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    $response = validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();

    expect($response->json('data.decision'))->toBe('validated')
        ->and($response->json('data.validated_amount.amount'))->toBe(500000)
        ->and($response->json('data.invoice.status'))->toBe('paid')
        ->and($response->json('data.invoice.validated_paid.amount'))->toBe(500000)
        ->and($response->json('data.receipt.receipt_number'))->toBeInt()
        ->and($response->json('data.receipt.downloadable'))->toBeFalse()
        ->and($response->json('data.receipt.file_generation_status'))->toBe('pending');

    // Exactly one immutable validated event for the group.
    $group = PaymentRecordingGroup::query()->firstOrFail();
    expect($group->status)->toBe(PaymentRecordingGroupStatus::Validated)
        ->and($group->validated_at)->not->toBeNull();
    expect(PaymentValidationEvent::query()->where('payment_recording_group_id', $group->id)->where('decision', 'validated')->count())->toBe(1);

    // Every component is validated coherently (no partial validation).
    $components = PaymentRecord::query()->where('payment_recording_group_id', $group->id)->get();
    $components->each(fn (PaymentRecord $c) => expect($c->status)->toBe(PaymentRecordStatus::Validated));
    expect($components->sum('validated_amount_minor'))->toBe(500000);

    // Invoice recognised balance + derived payment state.
    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->validated_paid_minor)->toBe(500000)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);

    // Exactly one original receipt for the validated group, durable + pending PDF.
    $receipts = Receipt::query()->get();
    expect($receipts)->toHaveCount(1);
    $receipt = $receipts->first();
    expect($receipt->amount_minor)->toBe(500000)
        ->and($receipt->reissue_of_receipt_id)->toBeNull()
        ->and($receipt->file_generation_status)->toBe('pending')
        ->and($receipt->components)->toHaveCount(1);

    // Durable per-component 20G commission handoff (no invented rate).
    $handoffs = CommissionHandoffEvent::query()->where('kind', 'validated_allocation')->get();
    expect($handoffs)->toHaveCount(1)
        ->and($handoffs->first()->amount_minor)->toBe(500000)
        ->and($handoffs->first()->payment_validation_event_id)->not->toBeNull();

    // The receipt PDF is produced by an outbox job dispatched AFTER commit — the
    // receipt is durable but not downloadable until the job flips it to ready.
    Queue::assertPushed(GenerateReceiptPdf::class);
});

it('marks the invoice partially_paid when the validated group is less than the total', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(200000)]);

    validatePaymentGroup($scn['finance'], $groupUlid)
        ->assertCreated()
        ->assertJsonPath('data.invoice.status', 'partially_paid');

    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->validated_paid_minor)->toBe(200000)
        ->and($invoice->status)->toBe(InvoiceStatus::PartiallyPaid);
});

it('validates a split group into one receipt whose components snapshot each method', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [
        cashComponent(150000),
        referencedComponent(350000, 'mpesa_offline', 'QGX7YT1ABC'),
    ]);

    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();

    $receipt = Receipt::query()->firstOrFail();
    expect($receipt->components)->toHaveCount(2)
        ->and(collect($receipt->components)->pluck('method')->all())->toEqualCanonicalizing(['cash', 'mpesa_offline'])
        // Safe snapshot only — never a reference.
        ->and(json_encode($receipt->components))->not->toContain('QGX7YT1ABC');
});

it('forbids Front Office from validating (403)', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    validatePaymentGroup($scn['frontOffice'], $groupUlid)->assertForbidden();

    expect(PaymentValidationEvent::query()->count())->toBe(0)
        ->and(Receipt::query()->count())->toBe(0);
});

it('forbids the recording maker from validating their own group (maker != checker)', function (): void {
    $scn = paymentScenario(500000);

    // Finance records via the maker-exception route (Finance becomes the group maker)...
    $groupUlid = (string) test()->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/invoices/{$scn['invoice']->ulid}/payment-recording-groups/exception", ['components' => [cashComponent(500000)]])
        ->assertCreated()
        ->json('data.id');

    // ...and the SAME Finance user cannot validate it.
    validatePaymentGroup($scn['finance'], $groupUlid)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'maker_is_checker');

    expect(PaymentValidationEvent::query()->count())->toBe(0)
        ->and(Receipt::query()->count())->toBe(0);
});

it('rejects validating a group that is not pending_validation (invalid_state_transition)', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    // First validation succeeds.
    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();

    // Second validation of the now-validated group is an invalid transition.
    validatePaymentGroup($scn['finance'], $groupUlid)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');

    expect(Receipt::query()->count())->toBe(1);
});

it('requires an Idempotency-Key and replays the stored validation without a second event/receipt', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);
    $key = (string) Str::uuid();

    // Missing/empty key → 422 idempotency_key_required (financial_mutation). An empty
    // header is sent explicitly because `recordPendingGroup` set a default
    // Idempotency-Key on the shared test client that would otherwise persist.
    test()->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', '')
        ->postJson("/api/v1/payment-recording-groups/{$groupUlid}/validate")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');

    validatePaymentGroup($scn['finance'], $groupUlid, $key)->assertCreated();
    // Replay of the same key returns the stored response, not a second event/receipt.
    validatePaymentGroup($scn['finance'], $groupUlid, $key)->assertCreated();

    expect(PaymentValidationEvent::query()->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(1);
});

it('returns 423 when the financial period is locked', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    // Bind a repository that reports the period locked.
    app()->bind(PeriodLockRepository::class, fn (): PeriodLockRepository => new class implements PeriodLockRepository
    {
        public function isLocked(int $merchantId, ?int $branchId, CarbonInterface $businessDate): bool
        {
            return true;
        }
    });

    validatePaymentGroup($scn['finance'], $groupUlid)
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'financial_period_locked');

    expect(PaymentValidationEvent::query()->count())->toBe(0)
        ->and(Receipt::query()->count())->toBe(0);
});

it('returns 404 for a foreign-tenant group ULID', function (): void {
    $scn = paymentScenario(500000);
    $other = paymentScenario(500000);
    $foreignGroupUlid = recordPendingGroup($other, [cashComponent(500000)]);

    validatePaymentGroup($scn['finance'], $foreignGroupUlid)->assertNotFound();
});
