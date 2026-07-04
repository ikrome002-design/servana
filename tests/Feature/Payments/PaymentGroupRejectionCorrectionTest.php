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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'payment-validation');

function rejectGroup(User $actor, string $groupUlid, string $reason = 'Reference did not match the bank statement.'): TestResponse
{
    return test()->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-recording-groups/{$groupUlid}/reject", ['reason' => $reason]);
}

function requestGroupCorrection(User $actor, string $groupUlid, string $reason = 'Please re-enter the M-Pesa reference.'): TestResponse
{
    return test()->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-recording-groups/{$groupUlid}/request-correction", ['reason' => $reason]);
}

it('rejects a whole group: one immutable event, components rejected, invoice unchanged, no receipt', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    rejectGroup($scn['finance'], $groupUlid)
        ->assertCreated()
        ->assertJsonPath('data.decision', 'rejected');

    $group = PaymentRecordingGroup::query()->firstOrFail();
    expect($group->status)->toBe(PaymentRecordingGroupStatus::Rejected)
        ->and($group->rejected_at)->not->toBeNull();

    PaymentRecord::query()->get()->each(fn (PaymentRecord $c) => expect($c->status)->toBe(PaymentRecordStatus::Rejected));

    expect(PaymentValidationEvent::query()->where('decision', 'rejected')->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(0);

    // Invoice untouched — no validated paid, no state change.
    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->validated_paid_minor)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued);
});

it('requires a reason to reject', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    rejectGroup($scn['finance'], $groupUlid, '')->assertStatus(422);
    expect(PaymentValidationEvent::query()->count())->toBe(0);
});

it('returns a group for correction, then a corrected group resubmits and validates', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    // Finance requests correction.
    requestGroupCorrection($scn['finance'], $groupUlid)
        ->assertCreated()
        ->assertJsonPath('data.decision', 'correction_required');

    $group = PaymentRecordingGroup::query()->firstOrFail();
    expect($group->status)->toBe(PaymentRecordingGroupStatus::CorrectionRequired);
    PaymentRecord::query()->get()->each(fn (PaymentRecord $c) => expect($c->status)->toBe(PaymentRecordStatus::CorrectionRequired));
    expect(Receipt::query()->count())->toBe(0);

    // Resubmit (reference_correct authority) returns it to pending_validation.
    test()->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-recording-groups/{$groupUlid}/resubmit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_validation');

    $group->refresh();
    expect($group->status)->toBe(PaymentRecordingGroupStatus::PendingValidation);
    PaymentRecord::query()->get()->each(fn (PaymentRecord $c) => expect($c->status)->toBe(PaymentRecordStatus::PendingValidation));
});

it('cannot resubmit a group that is not correction_required', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    // The group is pending_validation, not correction_required → invalid transition.
    test()->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-recording-groups/{$groupUlid}/resubmit")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');
});

it('forbids Front Office from rejecting or requesting correction (403)', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    rejectGroup($scn['frontOffice'], $groupUlid)->assertForbidden();
    requestGroupCorrection($scn['frontOffice'], $groupUlid)->assertForbidden();
    expect(PaymentValidationEvent::query()->count())->toBe(0);
});

it('forbids the recording maker from rejecting their own group (maker != checker)', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = (string) test()->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/invoices/{$scn['invoice']->ulid}/payment-recording-groups/exception", ['components' => [cashComponent(500000)]])
        ->assertCreated()->json('data.id');

    rejectGroup($scn['finance'], $groupUlid)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'maker_is_checker');
});
