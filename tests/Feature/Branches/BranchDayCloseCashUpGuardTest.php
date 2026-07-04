<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Services\BranchClosureGuard;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Receipts\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('payments', 'cash-up', 'branches');

function financialBlockers(array $scn): array
{
    return app(BranchClosureGuard::class)->financialDayCloseBlockers($scn['branch']->fresh(), cashUpBusinessDate());
}

function approvedCashUp(array $scn): BranchCashUp
{
    return BranchCashUp::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'business_date' => cashUpBusinessDate(),
        'status' => CashUpStatus::Locked,
    ]);
}

/** A same-tenant receipt (via a coherent group → validation-event chain) in $genStatus. */
function scenarioReceipt(array $scn, string $genStatus): Receipt
{
    $group = PaymentRecordingGroup::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'invoice_id' => $scn['invoice']->id,
        'status' => PaymentRecordingGroupStatus::Validated,
        'submitted_for_validation_at' => CarbonImmutable::now(), 'validated_at' => CarbonImmutable::now(),
    ]);
    $event = PaymentValidationEvent::factory()->create([
        'payment_recording_group_id' => $group->id,
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'invoice_id' => $scn['invoice']->id,
    ]);

    return Receipt::factory()->create([
        'payment_validation_event_id' => $event->id,
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'invoice_id' => $scn['invoice']->id,
        'file_generation_status' => $genStatus,
    ]);
}

it('blocks day close when the branch-day cash-up is missing', function (): void {
    $scn = cashUpScenario();
    expect(financialBlockers($scn))->toContain('cash_up_not_approved');
});

it('blocks day close while the cash-up is only submitted (not approved)', function (): void {
    $scn = cashUpScenario();
    BranchCashUp::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'business_date' => cashUpBusinessDate(), 'status' => CashUpStatus::Submitted,
    ]);
    expect(financialBlockers($scn))->toContain('cash_up_not_approved');
});

it('blocks day close while a payment group awaits validation', function (): void {
    $scn = cashUpScenario();
    approvedCashUp($scn);
    cashUpComponent($scn, PaymentMethod::Cash, 100000, PaymentRecordStatus::PendingValidation);

    expect(financialBlockers($scn))->toContain('pending_payment_validations')
        ->and(financialBlockers($scn))->not->toContain('cash_up_not_approved');
});

it('blocks day close while an issued receipt PDF is not yet generated', function (): void {
    $scn = cashUpScenario();
    approvedCashUp($scn);
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    scenarioReceipt($scn, 'pending');

    expect(financialBlockers($scn))->toContain('unissued_receipts');
});

it('allows day close when the cash-up is approved, no validation pends, and receipts are ready', function (): void {
    $scn = cashUpScenario();
    approvedCashUp($scn);
    // A validated payment + a ready receipt (terminal/settled) must NOT block.
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    scenarioReceipt($scn, 'ready');

    expect(financialBlockers($scn))->toBe([]);

    // End-to-end: the Branch Manager can now close the open day (opened by the scenario).
    test()->actingAs($scn['branchManager'], 'sanctum')
        ->postJson("/api/v1/branches/{$scn['branch']->ulid}/day/close")
        ->assertStatus(200)->assertJsonPath('data.status', 'closed');
});

it('reports the financial blocker through the day-close endpoint when the cash-up is missing', function (): void {
    $scn = cashUpScenario(); // opens today's branch day via the scenario

    test()->actingAs($scn['branchManager'], 'sanctum')
        ->postJson("/api/v1/branches/{$scn['branch']->ulid}/day/close")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'branch_closure_blocked')
        ->assertJsonPath('error.meta.blockers', ['cash_up_not_approved']);
});
