<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Actions\ApproveCashUp;
use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Exceptions\CashUpException;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('payments', 'cash-up');

it('derives expected totals from validated components only and runs draft → submit → approve → lock', function (): void {
    $scn = cashUpScenario();
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 300000);
    cashUpValidatedComponent($scn, PaymentMethod::MpesaOffline, 150000);
    // A pending (unvalidated) component must NOT contribute to expected.
    cashUpComponent($scn, PaymentMethod::Cash, 999999, PaymentRecordStatus::PendingValidation);

    // Server-derived expected preview (no counts yet).
    $preview = test()->actingAs($scn['branchManager'], 'sanctum')
        ->getJson("/api/v1/branches/{$scn['branch']->ulid}/cash-ups/".cashUpBusinessDate())
        ->assertOk();
    expect($preview->json('data.expected_minor'))->toBe(450000)
        ->and($preview->json('data.counted_minor'))->toBe(0);

    // Branch Manager enters counts (client never sets expected).
    $draft = putDraft($scn, [
        ['method' => 'cash', 'counted_minor' => 300000],
        ['method' => 'mpesa_offline', 'counted_minor' => 149000],
    ])->assertOk();
    expect($draft->json('data.expected_minor'))->toBe(450000)
        ->and($draft->json('data.counted_minor'))->toBe(449000)
        ->and($draft->json('data.variance_minor'))->toBe(-1000)
        ->and($draft->json('data.status'))->toBe('draft');

    $cashUpUlid = (string) BranchCashUp::query()->firstOrFail()->ulid;

    cashUpPost($scn['branchManager'], "/api/v1/cash-ups/{$cashUpUlid}/submit")
        ->assertOk()->assertJsonPath('data.status', 'submitted');

    cashUpPost($scn['finance'], "/api/v1/cash-ups/{$cashUpUlid}/approve")
        ->assertOk()->assertJsonPath('data.status', 'approved');

    cashUpPost($scn['finance'], "/api/v1/cash-ups/{$cashUpUlid}/lock")
        ->assertOk()->assertJsonPath('data.status', 'locked');

    $cashUp = BranchCashUp::query()->firstOrFail();
    expect($cashUp->status)->toBe(CashUpStatus::Locked)
        ->and($cashUp->expected_minor)->toBe(450000);
    // split_payment is never a line.
    expect($cashUp->lines()->where('method', 'split_payment')->count())->toBe(0);
});

it('subtracts finalized refunds of the method from the expected total (refund treatment)', function (): void {
    $scn = cashUpScenario();
    $component = cashUpValidatedComponent($scn, PaymentMethod::Cash, 300000);
    Refund::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'invoice_id' => $scn['invoice']->id,
        'payment_record_id' => $component->id, 'method' => PaymentMethod::Cash, 'amount_minor' => 50000,
        'status' => RefundStatus::Finalized,
        'approved_by' => $scn['finance']->id, 'approved_at' => CarbonImmutable::now(),
        'finalized_by' => $scn['finance']->id, 'finalized_at' => CarbonImmutable::now('Africa/Nairobi'),
    ]);
    // A non-finalized (requested) refund must NOT reduce expected.
    Refund::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'invoice_id' => $scn['invoice']->id,
        'payment_record_id' => $component->id, 'method' => PaymentMethod::Cash, 'amount_minor' => 70000,
        'status' => RefundStatus::Requested,
    ]);

    $draft = putDraft($scn, [['method' => 'cash', 'counted_minor' => 250000]])->assertOk();
    expect($draft->json('data.expected_minor'))->toBe(250000) // 300000 - 50000 finalized
        ->and($draft->json('data.variance_minor'))->toBe(0);
});

it('forbids the Branch Manager maker from approving; a distinct Finance checker approves (maker/checker)', function (): void {
    $scn = cashUpScenario();
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 100000]])->assertOk();
    $ulid = (string) BranchCashUp::query()->firstOrFail()->ulid;
    cashUpPost($scn['branchManager'], "/api/v1/cash-ups/{$ulid}/submit")->assertOk();

    // The maker (Branch Manager) holds branch.cash_up.submit but NOT cash_up.approve
    // (registry incompatibility) → 403 at the permission boundary.
    cashUpPost($scn['branchManager'], "/api/v1/cash-ups/{$ulid}/approve")->assertForbidden();

    // A DISTINCT Finance checker approves successfully.
    cashUpPost($scn['finance'], "/api/v1/cash-ups/{$ulid}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
});

it('blocks the actor guard when the checker id equals the submitter id (defence in depth)', function (): void {
    $scn = cashUpScenario();
    /** @var User $finance */
    $finance = $scn['finance'];
    // Force the normally-impossible both-keys situation to exercise the per-transaction
    // actor guard directly: a submitted cash-up whose submitter is the approving user.
    $cashUp = BranchCashUp::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'business_date' => cashUpBusinessDate(),
        'status' => CashUpStatus::Submitted,
        'submitted_by' => $finance->id,
        'submitted_at' => CarbonImmutable::now(),
    ]);

    $this->expectException(CashUpException::class);
    app(ApproveCashUp::class)->handle($cashUp, $finance);
});

it('blocks approving a cash-up that is not submitted (invalid_state_transition)', function (): void {
    $scn = cashUpScenario();
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 100000]])->assertOk();
    $ulid = (string) BranchCashUp::query()->firstOrFail()->ulid;

    // Approve from draft is invalid.
    cashUpPost($scn['finance'], "/api/v1/cash-ups/{$ulid}/approve")
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');
});

it('runs the correction cycle: submitted → correction_requested → resubmit → approve', function (): void {
    $scn = cashUpScenario();
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 90000]])->assertOk();
    $ulid = (string) BranchCashUp::query()->firstOrFail()->ulid;
    cashUpPost($scn['branchManager'], "/api/v1/cash-ups/{$ulid}/submit")->assertOk();

    cashUpPost($scn['finance'], "/api/v1/cash-ups/{$ulid}/request-correction", ['reason' => 'Recount the cash drawer.'])
        ->assertOk()->assertJsonPath('data.status', 'correction_requested');

    // Branch Manager corrects the count then resubmits.
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 100000]])->assertOk()->assertJsonPath('data.counted_minor', 100000);
    cashUpPost($scn['branchManager'], "/api/v1/cash-ups/{$ulid}/resubmit")->assertOk()->assertJsonPath('data.status', 'submitted');
    cashUpPost($scn['finance'], "/api/v1/cash-ups/{$ulid}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
});

it('requires a reason to reject or request correction', function (): void {
    $scn = cashUpScenario();
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 100000]])->assertOk();
    $ulid = (string) BranchCashUp::query()->firstOrFail()->ulid;
    cashUpPost($scn['branchManager'], "/api/v1/cash-ups/{$ulid}/submit")->assertOk();

    cashUpPost($scn['finance'], "/api/v1/cash-ups/{$ulid}/reject", ['reason' => ''])->assertStatus(422);
});

it('does not destructively overwrite a submitted cash-up snapshot via the draft PUT', function (): void {
    $scn = cashUpScenario();
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 100000]])->assertOk();
    $ulid = (string) BranchCashUp::query()->firstOrFail()->ulid;
    cashUpPost($scn['branchManager'], "/api/v1/cash-ups/{$ulid}/submit")->assertOk();

    // A draft PUT on a submitted cash-up is rejected (not silently overwritten).
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 5]])
        ->assertStatus(422)->assertJsonPath('error.code', 'cash_up_not_editable');
    expect(BranchCashUp::query()->firstOrFail()->counted_minor)->toBe(100000);
});

it('emits typed cash-up audit events across the lifecycle', function (): void {
    $scn = cashUpScenario();
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 100000]])->assertOk();
    $ulid = (string) BranchCashUp::query()->firstOrFail()->ulid;
    cashUpPost($scn['branchManager'], "/api/v1/cash-ups/{$ulid}/submit")->assertOk();
    cashUpPost($scn['finance'], "/api/v1/cash-ups/{$ulid}/approve")->assertOk();
    cashUpPost($scn['finance'], "/api/v1/cash-ups/{$ulid}/lock")->assertOk();

    $actions = AuditLog::query()->pluck('action')->all();
    expect($actions)->toContain(AuditEvent::CashUpDraftUpdated->value)
        ->and($actions)->toContain(AuditEvent::CashUpSubmitted->value)
        ->and($actions)->toContain(AuditEvent::CashUpApproved->value)
        ->and($actions)->toContain(AuditEvent::CashUpLocked->value);
});
