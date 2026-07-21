<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('compensation', 'phase20h', 'phase20h-api');

/*
 | Phase 20H Increment 5 — HR / Finance / Merchant-Administrator payout-run API (Plan §62, §25.5, §19.3).
 | HR drafts (branch scope); Finance verifies/approves/rejects/marks-paid (merchant scope; MFA + fresh
 | step-up + Idempotency-Key on the financial-mutation routes); the Merchant Administrator holds ONLY the
 | compensation-summary read + high-value approval. Servana MOVES NO MONEY — mark-paid records an
 | external settlement outcome only. Every mutation runs through the real domain action.
 */

/**
 * @return array{merchant: Merchant, branch: MerchantBranch, staff: StaffProfile, hr: User,
 *   finance: User, admin: User, frontOffice: User}
 */
function payoutApiScn(?int $threshold = null): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    MerchantSubscription::factory()->create([
        'merchant_id' => $merchant->id,
        'high_value_payout_threshold_minor' => $threshold,
    ]);
    $staff = StaffProfile::factory()->create(['merchant_id' => $merchant->id, 'primary_branch_id' => $branch->id]);

    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [$finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);
    [$admin] = branchStaff($merchant, $branch, MerchantUserRole::MerchantAdmin, assigned: false);
    [$frontOffice] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    return compact('merchant', 'branch', 'staff', 'hr', 'finance', 'admin', 'frontOffice');
}

/** Seed one eligible commission + salary for the scenario staff (KES). */
function seedEligible(array $scn, int $commission = 50000, int $salary = 300000): void
{
    CommissionLedgerEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $scn['staff']->id,
        'amount_minor' => $commission, 'currency' => 'KES', 'earned_at' => '2026-07-15 09:00:00',
    ]);
    SalaryLedgerEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $scn['staff']->id,
        'amount_minor' => $salary, 'currency' => 'KES',
    ]);
}

/** HR creates a draft run over July 2026 via the API and returns its ULID. */
function hrCreateRun(array $scn): string
{
    return test()->actingAs($scn['hr'], 'sanctum')
        ->postJson('/api/v1/hr/payout-runs', [
            'branch_ulid' => $scn['branch']->ulid,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'currency' => 'KES',
        ])->assertCreated()->json('data.id');
}

/** Idempotency-Key header helper. */
function idem(): array
{
    return ['Idempotency-Key' => 'p20h-'.Str::random(24)];
}

// ===== HR draft workflow ==========================================================================

it('lets HR create a draft run that server-snapshots eligible items (no client totals)', function (): void {
    $scn = payoutApiScn();
    seedEligible($scn);

    $data = test()->actingAs($scn['hr'], 'sanctum')
        ->postJson('/api/v1/hr/payout-runs', [
            'branch_ulid' => $scn['branch']->ulid,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'currency' => 'KES',
            // A malicious client tries to force a total + status — both must be ignored/rejected.
            'gross_total_minor' => 999999999,
        ])->assertStatus(422)->json(); // gross_total_minor is a prohibited server-owned field

    // Now the clean create succeeds and the server computes the total from the ledgers.
    $body = test()->actingAs($scn['hr'], 'sanctum')
        ->postJson('/api/v1/hr/payout-runs', [
            'branch_ulid' => $scn['branch']->ulid, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'currency' => 'KES',
        ])->assertCreated();

    $body->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.currency', 'KES')
        ->assertJsonPath('data.gross_total_minor', 350000)
        ->assertJsonPath('data.item_count', 1);
    expect($body->json('data.id'))->toHaveLength(26);
    expect(AuditLog::query()->where('action', 'payout_run.created')->count())->toBe(1);
});

it('lets HR update a draft (re-snapshot), submit (freeze), and shows items on detail', function (): void {
    $scn = payoutApiScn();
    seedEligible($scn);
    $ulid = hrCreateRun($scn);

    // Add more eligible commission, then update-draft re-snapshots the higher total.
    earnedCommission($scn['branch'], $scn['staff'], 20000);
    test()->actingAs($scn['hr'], 'sanctum')
        ->patchJson("/api/v1/hr/payout-runs/{$ulid}", ['period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'currency' => 'KES'])
        ->assertOk()->assertJsonPath('data.gross_total_minor', 370000);

    // Submit freezes the run + claims the ledgers.
    test()->actingAs($scn['hr'], 'sanctum')
        ->postJson("/api/v1/hr/payout-runs/{$ulid}/submit")
        ->assertOk()->assertJsonPath('data.status', 'submitted');

    // Detail exposes items with masked staff refs + statement flag.
    $item = test()->actingAs($scn['hr'], 'sanctum')->getJson("/api/v1/hr/payout-runs/{$ulid}")
        ->assertOk()->json('data.items.0');
    expect($item['staff_profile_id'])->toBe($scn['staff']->ulid)
        ->and($item['has_statement'])->toBeFalse()
        ->and($item)->not->toHaveKeys(['source_ledger_refs', 'merchant_id']);

    // The claimed ledger now carries a payout_item_id (server side effect of submit).
    expect(CommissionLedgerEntry::query()->whereNotNull('payout_item_id')->count())->toBeGreaterThan(0);
});

it('lets HR cancel a draft and forbids editing a submitted run', function (): void {
    $scn = payoutApiScn();
    seedEligible($scn);
    $ulid = hrCreateRun($scn);

    test()->actingAs($scn['hr'], 'sanctum')->postJson("/api/v1/hr/payout-runs/{$ulid}/submit")->assertOk();

    // A frozen (submitted) run cannot be updated → 422 invalid_state_transition.
    test()->actingAs($scn['hr'], 'sanctum')
        ->patchJson("/api/v1/hr/payout-runs/{$ulid}", ['period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'currency' => 'KES'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');

    // A fresh draft can be cancelled.
    $second = hrCreateRun($scn);
    test()->actingAs($scn['hr'], 'sanctum')->postJson("/api/v1/hr/payout-runs/{$second}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('forbids HR from Finance + Merchant-Admin actions and forbids non-HR from HR actions', function (): void {
    $scn = payoutApiScn();
    seedEligible($scn);
    $ulid = hrCreateRun($scn);
    test()->actingAs($scn['hr'], 'sanctum')->postJson("/api/v1/hr/payout-runs/{$ulid}/submit")->assertOk();

    // HR cannot verify / approve / mark-paid (no such permission).
    test()->actingAs($scn['hr'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/verify")->assertForbidden();
    test()->actingAs($scn['hr'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/mark-paid", ['external_payment_reference' => 'X123', 'paid_date' => '2026-07-15'])
        ->assertForbidden();

    // Front Office (no payout key at all) cannot create a draft.
    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson('/api/v1/hr/payout-runs', ['branch_ulid' => $scn['branch']->ulid, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'currency' => 'KES'])
        ->assertForbidden();
});

it('isolates branches: HR cannot draft a run for a foreign branch', function (): void {
    $scn = payoutApiScn();
    $foreignBranch = MerchantBranch::factory()->create(); // different merchant

    test()->actingAs($scn['hr'], 'sanctum')
        ->postJson('/api/v1/hr/payout-runs', ['branch_ulid' => $foreignBranch->ulid, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'currency' => 'KES'])
        ->assertNotFound();
});

// ===== Finance verify → approve → mark-paid =======================================================

/** Drive a run from draft to approved (ordinary) and return its ULID. */
function approvedRun(array $scn): string
{
    $ulid = hrCreateRun($scn);
    test()->actingAs($scn['hr'], 'sanctum')->postJson("/api/v1/hr/payout-runs/{$ulid}/submit")->assertOk();
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())->postJson("/api/v1/finance/payout-runs/{$ulid}/verify")->assertOk();
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())->postJson("/api/v1/finance/payout-runs/{$ulid}/approve")->assertOk();

    return $ulid;
}

it('lets Finance verify, approve, and mark-paid an ordinary run with ledger propagation', function (): void {
    $scn = payoutApiScn(); // null threshold → high-value gate inactive → ordinary approval
    seedEligible($scn);
    $ulid = approvedRun($scn);

    test()->actingAs($scn['finance'], 'sanctum')->getJson("/api/v1/finance/payout-runs/{$ulid}")
        ->assertOk()->assertJsonPath('data.status', 'approved');

    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/mark-paid", ['external_payment_reference' => 'MPESA-REF-778', 'paid_date' => '2026-07-15'])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.has_external_payment_reference', true);

    // The linked ledgers advanced forward to paid; the raw reference is encrypted (never returned raw).
    expect(SalaryLedgerEntry::query()->where('status', 'paid')->count())->toBe(1);
    expect(CommissionLedgerEntry::query()->where('status', 'paid')->count())->toBe(1);
    $run = PersonnelPayoutRun::query()->where('ulid', $ulid)->firstOrFail();
    expect($run->external_payment_reference_encrypted)->toBe('MPESA-REF-778'); // decrypted via cast, stored encrypted

    // Critical audit; the raw reference is NEVER in the audit payload.
    $audit = AuditLog::query()->where('action', 'payout_run.marked_paid')->firstOrFail();
    expect(json_encode($audit->context ?? []))->not->toContain('MPESA-REF-778');
});

it('requires an approved status, external reference, paid date, fresh step-up, and Idempotency-Key to mark paid', function (): void {
    $scn = payoutApiScn();
    seedEligible($scn);

    // Not yet approved → invalid_state_transition.
    $ulid = hrCreateRun($scn);
    test()->actingAs($scn['hr'], 'sanctum')->postJson("/api/v1/hr/payout-runs/{$ulid}/submit")->assertOk();
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/mark-paid", ['external_payment_reference' => 'REF', 'paid_date' => '2026-07-15'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');

    $approved = approvedRun($scn); // separate run

    // Missing external reference / paid date → 422 validation.
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$approved}/mark-paid", ['paid_date' => '2026-07-15'])->assertStatus(422);
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$approved}/mark-paid", ['external_payment_reference' => 'REF'])->assertStatus(422);

    // Stale step-up → 403 step_up_required (no state change). The Finance user is already MFA-enrolled
    // (the default actingAs MFA session provisioned a credential in approvedRun), so a stale assertion is
    // enough to prove the step-up gate without re-enrolling.
    $stale = now()->subMinutes((int) config('servana.mfa.step_up_window_minutes') + 1)->getTimestamp();
    test()->statefulMfa($stale)->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$approved}/mark-paid", ['external_payment_reference' => 'REF', 'paid_date' => '2026-07-15'])
        ->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');

    expect(PersonnelPayoutRun::query()->where('ulid', $approved)->value('status'))->toBe(PayoutRunStatus::Approved);
});

it('requires an Idempotency-Key on the mark-paid POST', function (): void {
    $scn = payoutApiScn();
    seedEligible($scn);
    // Build a submitted run via HR routes only (branch_mutation — no idempotency header is ever set on
    // the shared test client), so the no-header mark-paid below is a clean missing-key request. The
    // idempotency middleware fires BEFORE the state check, so a submitted (not yet approved) run is fine.
    $ulid = hrCreateRun($scn);
    test()->actingAs($scn['hr'], 'sanctum')->postJson("/api/v1/hr/payout-runs/{$ulid}/submit")->assertOk();

    test()->actingAs($scn['finance'], 'sanctum')
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/mark-paid", ['external_payment_reference' => 'REF', 'paid_date' => '2026-07-15'])
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');

    expect(PersonnelPayoutRun::query()->where('ulid', $ulid)->value('status'))->toBe(PayoutRunStatus::Submitted);
});

it('is idempotent: replaying mark-paid with the same key does not double-settle', function (): void {
    $scn = payoutApiScn();
    seedEligible($scn);
    $ulid = approvedRun($scn);
    $headers = idem();
    $body = ['external_payment_reference' => 'REF-IDEM', 'paid_date' => '2026-07-15'];

    test()->actingAs($scn['finance'], 'sanctum')->withHeaders($headers)->postJson("/api/v1/finance/payout-runs/{$ulid}/mark-paid", $body)->assertOk();
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders($headers)->postJson("/api/v1/finance/payout-runs/{$ulid}/mark-paid", $body)->assertOk();

    expect(AuditLog::query()->where('action', 'payout_run.marked_paid')->count())->toBe(1);
    expect(SalaryLedgerEntry::query()->where('status', 'paid')->count())->toBe(1);
});

it('lets Finance reject a submitted run and releases the claimed ledgers', function (): void {
    $scn = payoutApiScn();
    seedEligible($scn);
    $ulid = hrCreateRun($scn);
    test()->actingAs($scn['hr'], 'sanctum')->postJson("/api/v1/hr/payout-runs/{$ulid}/submit")->assertOk();
    expect(CommissionLedgerEntry::query()->whereNotNull('payout_item_id')->count())->toBe(1);

    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/reject", ['reason' => 'Period miscalculated; redo the draft.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');

    // Release: the ledgers return to the eligible pool (payout_item_id cleared).
    expect(CommissionLedgerEntry::query()->whereNotNull('payout_item_id')->count())->toBe(0);
});

// ===== High-value routing + Merchant-Admin approval ===============================================

it('routes a high-value run to Merchant-Admin approval and keeps Finance off standard-approve', function (): void {
    $scn = payoutApiScn(threshold: 100000); // gross 350000 > 100000 → high-value
    seedEligible($scn);
    $ulid = hrCreateRun($scn);
    test()->actingAs($scn['hr'], 'sanctum')->postJson("/api/v1/hr/payout-runs/{$ulid}/submit")->assertOk();

    // Verify routes it to pending_merchant_admin_approval.
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/verify")->assertOk()
        ->assertJsonPath('data.status', 'pending_merchant_admin_approval')
        ->assertJsonPath('data.is_high_value', true);

    // Finance standard-approve is rejected (wrong source state).
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/approve")->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');

    // Merchant Admin sees it in the high-value queue and approves it (critical audit).
    test()->actingAs($scn['admin'], 'sanctum')->getJson('/api/v1/merchant/payout-runs')
        ->assertOk()->assertJsonPath('data.0.id', $ulid);
    test()->actingAs($scn['admin'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/merchant/payout-runs/{$ulid}/approve-high-value")->assertOk()->assertJsonPath('data.status', 'approved');

    expect(AuditLog::query()->where('action', 'payout_run.high_value_approved')->count())->toBe(1);
});

it('forbids the Merchant Administrator from editing drafts or marking paid', function (): void {
    $scn = payoutApiScn(threshold: 100000);
    seedEligible($scn);
    $ulid = approvedRunHighValue($scn);

    // MA cannot mark paid (no such permission).
    test()->actingAs($scn['admin'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/mark-paid", ['external_payment_reference' => 'R', 'paid_date' => '2026-07-15'])
        ->assertForbidden();

    // MA cannot create an HR draft.
    test()->actingAs($scn['admin'], 'sanctum')
        ->postJson('/api/v1/hr/payout-runs', ['branch_ulid' => $scn['branch']->ulid, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'currency' => 'KES'])
        ->assertForbidden();
});

/** Drive a high-value run to approved via Merchant-Admin and return its ULID. */
function approvedRunHighValue(array $scn): string
{
    $ulid = hrCreateRun($scn);
    test()->actingAs($scn['hr'], 'sanctum')->postJson("/api/v1/hr/payout-runs/{$ulid}/submit")->assertOk();
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())->postJson("/api/v1/finance/payout-runs/{$ulid}/verify")->assertOk();
    test()->actingAs($scn['admin'], 'sanctum')->withHeaders(idem())->postJson("/api/v1/merchant/payout-runs/{$ulid}/approve-high-value")->assertOk();

    return $ulid;
}

// ===== Merchant-Admin compensation summary ========================================================

it('returns a currency-grouped compensation summary to the Merchant Administrator', function (): void {
    $scn = payoutApiScn();
    seedEligible($scn);
    $ulid = approvedRun($scn);
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$ulid}/mark-paid", ['external_payment_reference' => 'REF-SUM', 'paid_date' => '2026-07-15'])->assertOk();

    $data = test()->actingAs($scn['admin'], 'sanctum')->getJson('/api/v1/merchant/compensation-summary')
        ->assertOk()->json('data');

    expect($data['payout_runs_by_status']['paid'])->toBe(1);
    expect($data['pending_high_value_approvals'])->toBe(0);
    expect($data['paid_by_currency'][0]['currency'])->toBe('KES');
    expect($data['paid_by_currency'][0]['paid_gross_minor'])->toBe(350000);

    // Finance cannot read the Merchant-Admin summary.
    test()->actingAs($scn['finance'], 'sanctum')->getJson('/api/v1/merchant/compensation-summary')->assertForbidden();
});

it('isolates tenants: a foreign run ULID 404s for Finance', function (): void {
    $scn = payoutApiScn();
    $otherScn = payoutApiScn();
    seedEligible($otherScn);
    $foreignUlid = hrCreateRun($otherScn);

    test()->actingAs($scn['finance'], 'sanctum')->getJson("/api/v1/finance/payout-runs/{$foreignUlid}")->assertNotFound();
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idem())
        ->postJson("/api/v1/finance/payout-runs/{$foreignUlid}/verify")->assertNotFound();
});
