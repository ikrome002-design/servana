<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('payments', 'refunds', 'mfa');

beforeEach(fn () => Queue::fake([GenerateReceiptPdf::class]));

function refundReadyForApproval(): array
{
    $scn = paymentScenario(500000);
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(500000)]))->assertCreated();
    $component = PaymentRecord::query()->firstOrFail();

    [$approver, $approverMembership] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Finance);
    grantOverride($approverMembership, 'refund.approve');
    grantOverride($approverMembership, 'refund.finalize');
    confirmedTotp($approver);

    $refundUlid = (string) test()->actingAs($scn['finance'], 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/refunds', ['payment_record' => $component->ulid, 'amount_minor' => 500000, 'method' => 'cash', 'reason' => 'return'])
        ->assertCreated()->json('data.id');

    return [$refundUlid, $approver];
}

function staleAssertion(): int
{
    return now()->subMinutes((int) config('servana.mfa.step_up_window_minutes') + 1)->getTimestamp();
}

it('denies refund approval without a fresh MFA step-up', function (): void {
    [$refundUlid, $approver] = refundReadyForApproval();

    // Asserted (privileged gate passes) but STALE → step_up_required.
    test()->statefulMfa(staleAssertion())->actingAs($approver, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/refunds/{$refundUlid}/approve")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'step_up_required');

    expect(Refund::query()->firstOrFail()->status)->toBe(RefundStatus::Requested);
});

it('denies refund finalization without a fresh MFA step-up', function (): void {
    [$refundUlid, $approver] = refundReadyForApproval();

    // Approve WITH fresh step-up.
    test()->statefulMfa(now()->getTimestamp())->actingAs($approver, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/refunds/{$refundUlid}/approve")->assertOk();

    // Finalize with a STALE assertion → step_up_required.
    test()->statefulMfa(staleAssertion())->actingAs($approver, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/refunds/{$refundUlid}/finalize")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'step_up_required');

    expect(Refund::query()->firstOrFail()->status)->toBe(RefundStatus::Approved);
});
