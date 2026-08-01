<?php

declare(strict_types=1);

use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Compensation\Actions\ApprovePayoutRunHighValue;
use App\Domain\Compensation\Actions\ApprovePayoutRunStandard;
use App\Domain\Compensation\Actions\CancelPayoutRunDraft;
use App\Domain\Compensation\Actions\MarkPayoutRunPaid;
use App\Domain\Compensation\Actions\RejectPayoutRun;
use App\Domain\Compensation\Actions\SubmitPayoutRun;
use App\Domain\Compensation\Actions\UpdatePayoutRunDraft;
use App\Domain\Compensation\Actions\VerifyPayoutRun;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('compensation', 'phase20h', 'phase20h-payout');

/*
 | Phase 20H payout-run domain lifecycle (Plan §62; §25.4/§25.5; §H4–H9). Every mutation runs through
 | the REAL action — no test writes a status directly. Proves snapshot, freeze/claim, verify + high-
 | value routing, ordinary + high-value approval, mark-paid ledger propagation, reject-release, and
 | eligibility (reversal netting, currency/branch isolation). Servana moves no money.
 */

// (payoutBranchStaff / earnedCommission / pendingSalary / draftRun are shared helpers in tests/Pest.php)

// ---- snapshot -------------------------------------------------------------------

it('snapshots eligible salary + commission + adjustment into one item per staff', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    pendingSalary($branch, $staff, 5000000);
    // Adjustment eligibility uses its Africa/Nairobi created_at business date; pin it inside this July run.
    CompensationAdjustment::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'branch_id' => $branch->id,
        'staff_profile_id' => $staff->id,
        'amount_minor' => -1000,
        'currency' => 'KES',
        'created_at' => '2026-07-15 09:00:00',
    ]);

    $run = draftRun($branch);

    expect($run->items()->count())->toBe(1);
    $item = $run->items()->first();
    expect($item->salary_amount_minor)->toBe(5000000)
        ->and($item->commission_amount_minor)->toBe(50000)
        ->and($item->adjustment_amount_minor)->toBe(-1000)
        ->and($item->gross_amount_minor)->toBe(5049000)
        ->and($run->gross_total_minor)->toBe(5049000);
});

it('excludes other-currency and other-branch liabilities', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000, 'KES');
    earnedCommission($branch, $staff, 99999, 'USD'); // other currency

    [$otherBranch, $otherStaff] = payoutBranchStaff();
    earnedCommission($otherBranch, $otherStaff, 77777, 'KES'); // other branch/merchant

    $run = draftRun($branch, 'KES');

    expect($run->gross_total_minor)->toBe(50000)
        ->and($run->items()->count())->toBe(1);
});

it('nets a reversed unpaid commission to zero (reversal + reversed original both excluded)', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $keep = earnedCommission($branch, $staff, 50000);
    $reversed = earnedCommission($branch, $staff, 30000);

    // Simulate a 20G unpaid reversal: original -> reversed, plus a negative earned reversal row.
    $reversed->update(['status' => 'reversed']);
    CommissionLedgerEntry::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'branch_id' => $branch->id,
        'staff_profile_id' => $staff->id,
        'amount_minor' => -30000,
        'currency' => 'KES',
        'earned_at' => '2026-07-15 09:00:00',
        'entry_type' => 'reversal',
        'reversal_reason' => 'refund_finalized',
        'status' => 'earned',
        'source_entry_id' => $reversed->id,
    ]);

    $run = draftRun($branch);

    expect($run->gross_total_minor)->toBe(50000); // only the kept row
});

// ---- freeze / claim -------------------------------------------------------------

it('claims the source ledgers on submit and freezes the run', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $commission = earnedCommission($branch, $staff, 50000);
    $salary = pendingSalary($branch, $staff, 5000000);

    $run = draftRun($branch);
    $submitted = app(SubmitPayoutRun::class)->handle($run, User::factory()->create());
    // Submit re-snapshots items (fresh eligibility), so read the claimed item AFTER submit.
    $item = $submitted->items()->first();

    expect($submitted->status)->toBe(PayoutRunStatus::Submitted);
    expect($commission->refresh()->payout_item_id)->toBe($item->id);
    expect($salary->refresh()->payout_item_id)->toBe($item->id);
    // Ledger status is UNCHANGED at submit (forward-only enums; claim is payout_item_id only).
    expect($commission->status->value)->toBe('earned');
    expect($salary->status->value)->toBe('pending');
});

it('refuses to update a submitted (frozen) run', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    $run = draftRun($branch);
    app(SubmitPayoutRun::class)->handle($run, User::factory()->create());

    expect(fn () => app(UpdatePayoutRunDraft::class)->handle(
        $run->refresh(), '2026-07-01', '2026-07-31', 'KES', User::factory()->create(),
    ))->toThrow(CompensationStateException::class);
});

// ---- ordinary approval + mark paid ----------------------------------------------

it('runs the ordinary lifecycle and propagates paid status to the ledgers', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $commission = earnedCommission($branch, $staff, 50000);
    $salary = pendingSalary($branch, $staff, 5000000);

    $run = draftRun($branch); // no threshold snapshot => ordinary
    app(SubmitPayoutRun::class)->handle($run, User::factory()->create());
    $verified = app(VerifyPayoutRun::class)->handle($run->refresh(), User::factory()->create());
    expect($verified->status)->toBe(PayoutRunStatus::FinanceVerified);

    app(ApprovePayoutRunStandard::class)->handle($run->refresh(), User::factory()->create());
    $paid = app(MarkPayoutRunPaid::class)->handle($run->refresh(), User::factory()->create(), 'MPESA-REF-123', '2026-08-01');

    expect($paid->status)->toBe(PayoutRunStatus::Paid)
        ->and($paid->paid_at->toDateString())->toBe('2026-08-01');
    expect($commission->refresh()->status->value)->toBe('paid');
    expect($salary->refresh()->status->value)->toBe('paid');
    // The external reference is encrypted at rest (decrypts through the cast).
    expect($paid->external_payment_reference_encrypted)->toBe('MPESA-REF-123');
});

// ---- high-value routing ---------------------------------------------------------

it('routes a high-value run through Merchant-Admin approval', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);

    // Snapshot a threshold below the gross by attaching a subscription threshold.
    MerchantSubscription::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'high_value_payout_threshold_minor' => 1000,
    ]);

    $run = draftRun($branch);
    expect($run->high_value_threshold_snapshot_minor)->toBe(1000);

    app(SubmitPayoutRun::class)->handle($run, User::factory()->create());
    $verified = app(VerifyPayoutRun::class)->handle($run->refresh(), User::factory()->create());
    expect($verified->status)->toBe(PayoutRunStatus::PendingMerchantAdminApproval);

    // Finance cannot standard-approve a run awaiting Merchant-Admin approval.
    expect(fn () => app(ApprovePayoutRunStandard::class)->handle($run->refresh(), User::factory()->create()))
        ->toThrow(CompensationStateException::class);

    $approved = app(ApprovePayoutRunHighValue::class)->handle($run->refresh(), User::factory()->create());
    expect($approved->status)->toBe(PayoutRunStatus::Approved);
});

// ---- reject / release -----------------------------------------------------------

it('releases claimed ledgers when a submitted run is rejected', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $commission = earnedCommission($branch, $staff, 50000);

    $run = draftRun($branch);
    app(SubmitPayoutRun::class)->handle($run, User::factory()->create());
    expect($commission->refresh()->payout_item_id)->not->toBeNull();

    $rejected = app(RejectPayoutRun::class)->handle($run->refresh(), User::factory()->create(), 'Wrong period.');

    expect($rejected->status)->toBe(PayoutRunStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Wrong period.');
    // Released back to the eligible pool: payout_item_id cleared, status still earned.
    expect($commission->refresh()->payout_item_id)->toBeNull();
    expect($commission->status->value)->toBe('earned');
});

it('cancels a draft run', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    $run = draftRun($branch);

    $cancelled = app(CancelPayoutRunDraft::class)->handle($run, User::factory()->create());
    expect($cancelled->status)->toBe(PayoutRunStatus::Cancelled);
});

it('cannot mark an unapproved run paid', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    $run = draftRun($branch);
    app(SubmitPayoutRun::class)->handle($run, User::factory()->create());

    expect(fn () => app(MarkPayoutRunPaid::class)->handle($run->refresh(), User::factory()->create(), 'REF', '2026-08-01'))
        ->toThrow(CompensationStateException::class);
});

it('records no payout money movement — a paid run only marks existing ledgers', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    $run = draftRun($branch);
    app(SubmitPayoutRun::class)->handle($run, User::factory()->create());
    app(VerifyPayoutRun::class)->handle($run->refresh(), User::factory()->create());
    app(ApprovePayoutRunStandard::class)->handle($run->refresh(), User::factory()->create());
    app(MarkPayoutRunPaid::class)->handle($run->refresh(), User::factory()->create(), 'REF', '2026-08-01');

    // No Wallet/provider tables exist; no new financial fact was created — only statuses advanced.
    foreach (['wallet_accounts', 'wallet_transactions'] as $forbidden) {
        expect(Schema::hasTable($forbidden))->toBeFalse();
    }
    expect(DB::table('commission_ledger')->count())->toBe(1); // no extra rows minted
});
