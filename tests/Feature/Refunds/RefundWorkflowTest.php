<?php

declare(strict_types=1);

use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'refunds');

beforeEach(fn () => Queue::fake([GenerateReceiptPdf::class]));

/**
 * Validate a full payment, then return [component, requester finance, approver finance].
 *
 * @return array{0: PaymentRecord, 1: User, 2: User, 3: array<string,mixed>}
 */
function refundScenario(int $amount = 500000): array
{
    $scn = paymentScenario($amount);
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent($amount)]))->assertCreated();
    $component = PaymentRecord::query()->where('merchant_id', $scn['merchant']->id)->firstOrFail();

    [$approver, $approverMembership] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Finance);
    grantOverride($approverMembership, 'refund.approve');
    grantOverride($approverMembership, 'refund.finalize');
    // Finance is privileged → the approver must be MFA-enrolled for the step-up routes.
    confirmedTotp($approver);

    return [$component, $scn['finance'], $approver, $scn];
}

function requestRefund(User $actor, string $componentUlid, int $amount, string $method = 'cash'): TestResponse
{
    return test()->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/refunds', ['payment_record' => $componentUlid, 'amount_minor' => $amount, 'method' => $method, 'reason' => 'Client returned service.']);
}

function approveRefund(User $actor, string $refundUlid, ?int $mfaAt = null): TestResponse
{
    return test()->statefulMfa($mfaAt ?? now()->getTimestamp())->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/refunds/{$refundUlid}/approve");
}

function finalizeRefund(User $actor, string $refundUlid, ?int $mfaAt = null): TestResponse
{
    return test()->statefulMfa($mfaAt ?? now()->getTimestamp())->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/refunds/{$refundUlid}/finalize");
}

it('runs request → approve → finalize, reducing the recognised balance non-destructively', function (): void {
    [$component, $requester, $approver] = refundScenario(500000);

    // Request → invoice enters refund_pending.
    $refundUlid = (string) requestRefund($requester, $component->ulid, 500000)->assertCreated()->json('data.id');
    expect(Invoice::query()->firstOrFail()->status)->toBe(InvoiceStatus::RefundPending);

    // Approve (distinct approver, fresh step-up) then finalize.
    approveRefund($approver, $refundUlid)->assertOk()->assertJsonPath('data.status', 'approved');
    finalizeRefund($approver, $refundUlid)->assertOk()->assertJsonPath('data.status', 'finalized');

    // Invoice recognised balance reduced to zero → issued; original rows preserved.
    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->validated_paid_minor)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued);

    // The component is fully reversed; the original payment record still exists.
    expect($component->refresh()->status)->toBe(PaymentRecordStatus::Reversed);
    expect(PaymentRecord::query()->count())->toBe(1);

    // A durable per-component reversal handoff was written (no invented rate).
    $reversal = CommissionHandoffEvent::query()->where('kind', 'reversal')->firstOrFail();
    expect($reversal->amount_minor)->toBe(500000)
        ->and($reversal->refund_id)->not->toBeNull();
});

it('marks a partial refund component adjusted and derives partially_paid', function (): void {
    [$component, $requester, $approver] = refundScenario(500000);

    $refundUlid = (string) requestRefund($requester, $component->ulid, 200000)->assertCreated()->json('data.id');
    approveRefund($approver, $refundUlid)->assertOk();
    finalizeRefund($approver, $refundUlid)->assertOk();

    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->validated_paid_minor)->toBe(300000)
        ->and($invoice->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($component->refresh()->status)->toBe(PaymentRecordStatus::Adjusted);
});

it('restores the prior paid state when a refund is rejected (validated_paid unchanged)', function (): void {
    [$component, $requester, $approver] = refundScenario(500000);

    $refundUlid = (string) requestRefund($requester, $component->ulid, 500000)->assertCreated()->json('data.id');
    expect(Invoice::query()->firstOrFail()->status)->toBe(InvoiceStatus::RefundPending);

    test()->actingAs($approver, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/refunds/{$refundUlid}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');

    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->validated_paid_minor)->toBe(500000);
});

it('forbids the requester from approving or finalizing their own refund (maker != checker)', function (): void {
    [$component, $requester] = refundScenario(500000);
    // Give the REQUESTER approve/finalize too, to prove the actor guard (not just the key).
    $requesterMembership = MerchantUser::query()->where('user_id', $requester->id)->firstOrFail();
    grantOverride($requesterMembership, 'refund.approve');
    grantOverride($requesterMembership, 'refund.finalize');

    $refundUlid = (string) requestRefund($requester, $component->ulid, 500000)->assertCreated()->json('data.id');

    approveRefund($requester, $refundUlid)->assertStatus(403)->assertJsonPath('error.code', 'maker_is_checker');
    expect(Refund::query()->firstOrFail()->status)->toBe(RefundStatus::Requested);
});
