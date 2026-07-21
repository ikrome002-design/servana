<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Actions\ApprovePayoutRunStandard;
use App\Domain\Compensation\Actions\CreatePayoutRunDraft;
use App\Domain\Compensation\Actions\MarkPayoutRunPaid;
use App\Domain\Compensation\Actions\SubmitPayoutRun;
use App\Domain\Compensation\Actions\VerifyPayoutRun;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('compensation', 'phase20h', 'phase20h-api');

/*
 | Phase 20H Increment 5 — Personnel own-scope earnings + earnings-query API (Plan §63, §10.2, §19.3;
 | §H10–H12). Personnel read ONLY their own earnings/statements/queries (the staff profile is derived
 | from the authenticated membership — never client-selectable); Finance is the sole authoritative
 | responder, and a monetary correction is an additive adjustment, never a ledger edit. No other staff's
 | data, no Wallet/provider field.
 */

/**
 * @return array{merchant: Merchant, branch: MerchantBranch, hr: User, finance: User,
 *   personnelUser: User, personnelStaff: StaffProfile, otherUser: User, otherStaff: StaffProfile}
 */
function earningsScn(): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    MerchantSubscription::factory()->create(['merchant_id' => $merchant->id, 'high_value_payout_threshold_minor' => null]);

    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [$finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);
    [$personnelUser, , $personnelStaff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);
    [$otherUser, , $otherStaff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    return compact('merchant', 'branch', 'hr', 'finance', 'personnelUser', 'personnelStaff', 'otherUser', 'otherStaff');
}

/** Drive a paid payout run for the scenario personnel through the real actions; return the paid item. */
function paidItemFor(array $scn, StaffProfile $staff): PersonnelPayoutItem
{
    earnedCommission($scn['branch'], $staff, 50000);
    $run = app(CreatePayoutRunDraft::class)->handle($scn['branch'], '2026-07-01', '2026-07-31', 'KES', $scn['hr']);
    app(SubmitPayoutRun::class)->handle($run, $scn['hr']);
    app(VerifyPayoutRun::class)->handle($run, $scn['finance']);
    app(ApprovePayoutRunStandard::class)->handle($run, $scn['finance']);
    app(MarkPayoutRunPaid::class)->handle($run, $scn['finance'], 'EXT-REF', '2026-07-15');

    return $run->items()->where('staff_profile_id', $staff->id)->firstOrFail();
}

function idemE(): array
{
    return ['Idempotency-Key' => 'p20he-'.Str::random(24)];
}

// ===== Personnel earnings reads ===================================================================

it('returns the personnel their own per-currency earnings overview + tab visibility', function (): void {
    $scn = earningsScn();
    earnedCommission($scn['branch'], $scn['personnelStaff'], 50000, 'KES');

    $data = test()->actingAs($scn['personnelUser'], 'sanctum')
        ->getJson('/api/v1/personnel/me/earnings')->assertOk()->json('data');

    expect($data['tab_visibility']['commission_tab'])->toBeTrue();
    $kes = collect($data['currencies'])->firstWhere('currency', 'KES');
    expect($kes['commission_unpaid_minor'])->toBe(50000);
});

it('never combines currencies in the personnel overview', function (): void {
    $scn = earningsScn();
    earnedCommission($scn['branch'], $scn['personnelStaff'], 50000, 'KES');
    earnedCommission($scn['branch'], $scn['personnelStaff'], 40000, 'USD');

    $rows = collect(test()->actingAs($scn['personnelUser'], 'sanctum')
        ->getJson('/api/v1/personnel/me/earnings')->assertOk()->json('data.currencies'))->keyBy('currency');

    expect($rows)->toHaveCount(2);
    expect($rows['KES']['commission_unpaid_minor'])->toBe(50000);
    expect($rows['USD']['commission_unpaid_minor'])->toBe(40000);
});

it('shows a personnel only their own payout history (never another staff member)', function (): void {
    $scn = earningsScn();
    paidItemFor($scn, $scn['personnelStaff']);
    paidItemFor($scn, $scn['otherStaff']);

    $rows = test()->actingAs($scn['personnelUser'], 'sanctum')
        ->getJson('/api/v1/personnel/me/payouts')->assertOk()->json('data');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['staff_profile_id'])->toBe($scn['personnelStaff']->ulid);
});

it('rejects a client-supplied staff_profile_ulid on an own-scope read (own scope is not selectable)', function (): void {
    $scn = earningsScn();

    test()->actingAs($scn['personnelUser'], 'sanctum')
        ->getJson('/api/v1/personnel/me/earnings?staff_profile_ulid='.$scn['otherStaff']->ulid)
        ->assertStatus(422);
});

it('denies the earnings read to a non-personnel role (Finance)', function (): void {
    $scn = earningsScn();

    test()->actingAs($scn['finance'], 'sanctum')->getJson('/api/v1/personnel/me/earnings')->assertForbidden();
});

// ===== Earnings statements ========================================================================

it('generates an own paid-item statement with a signed download link (idempotent)', function (): void {
    $scn = earningsScn();
    $item = paidItemFor($scn, $scn['personnelStaff']);

    $body = test()->actingAs($scn['personnelUser'], 'sanctum')
        ->postJson("/api/v1/personnel/me/payout-items/{$item->ulid}/statement")->assertOk();

    $body->assertJsonPath('data.statement.mime_type', 'application/pdf');
    expect($body->json('data.statement.id'))->toHaveLength(26);
    expect($body->json('data.download.url'))->toContain('/files/');

    // Idempotent: a second call returns the SAME statement file (no duplicate).
    $again = test()->actingAs($scn['personnelUser'], 'sanctum')
        ->postJson("/api/v1/personnel/me/payout-items/{$item->ulid}/statement")->assertOk();
    expect($again->json('data.statement.id'))->toBe($body->json('data.statement.id'));
    expect(AuditLog::query()->where('action', 'earnings_statement.generated')->count())->toBe(1);
});

it('denies a personnel a statement for another staff member (own-scope 404)', function (): void {
    $scn = earningsScn();
    $foreignItem = paidItemFor($scn, $scn['otherStaff']);

    test()->actingAs($scn['personnelUser'], 'sanctum')
        ->postJson("/api/v1/personnel/me/payout-items/{$foreignItem->ulid}/statement")->assertNotFound();
});

// ===== Earnings queries ===========================================================================

it('lets a personnel raise an own-scope earnings query and reads it back', function (): void {
    $scn = earningsScn();
    $ledger = earnedCommission($scn['branch'], $scn['personnelStaff'], 50000);

    $created = test()->actingAs($scn['personnelUser'], 'sanctum')
        ->postJson('/api/v1/personnel/me/earnings-queries', [
            'subject_type' => 'commission_ledger',
            'subject_ulid' => $ledger->ulid,
            'query_type' => 'commission_disagreement',
            'body' => 'My commission looks 500 short for this period.',
        ])->assertCreated();

    $created->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.assigned_role', 'finance')
        ->assertJsonPath('data.subject_type', 'commission_ledger');

    $ulid = $created->json('data.id');
    test()->actingAs($scn['personnelUser'], 'sanctum')
        ->getJson("/api/v1/personnel/me/earnings-queries/{$ulid}")->assertOk()->assertJsonPath('data.id', $ulid);
    expect(AuditLog::query()->where('action', 'earnings_query.created')->count())->toBe(1);
});

it('rejects a foreign subject and a server-owned field on query creation', function (): void {
    $scn = earningsScn();
    $foreignLedger = earnedCommission($scn['branch'], $scn['otherStaff'], 50000);

    // Foreign subject (another staff's ledger) → 404 (no existence leak).
    test()->actingAs($scn['personnelUser'], 'sanctum')
        ->postJson('/api/v1/personnel/me/earnings-queries', [
            'subject_type' => 'commission_ledger', 'subject_ulid' => $foreignLedger->ulid,
            'query_type' => 'commission_disagreement', 'body' => 'not my ledger',
        ])->assertNotFound();

    // Server-owned field (status) → 422.
    $ownLedger = earnedCommission($scn['branch'], $scn['personnelStaff'], 50000);
    test()->actingAs($scn['personnelUser'], 'sanctum')
        ->postJson('/api/v1/personnel/me/earnings-queries', [
            'subject_type' => 'commission_ledger', 'subject_ulid' => $ownLedger->ulid,
            'query_type' => 'commission_disagreement', 'body' => 'x', 'status' => 'resolved',
        ])->assertStatus(422);

    expect(EarningsQuery::query()->count())->toBe(0);
});

it('lets Finance resolve a query with a correction as an additive adjustment (never a ledger edit)', function (): void {
    $scn = earningsScn();
    $ledger = earnedCommission($scn['branch'], $scn['personnelStaff'], 50000);
    $original = $ledger->amount_minor;

    $ulid = test()->actingAs($scn['personnelUser'], 'sanctum')
        ->postJson('/api/v1/personnel/me/earnings-queries', [
            'subject_type' => 'commission_ledger', 'subject_ulid' => $ledger->ulid,
            'query_type' => 'commission_disagreement', 'body' => 'short by 500',
        ])->assertCreated()->json('data.id');

    $resolved = test()->actingAs($scn['finance'], 'sanctum')->withHeaders(idemE())
        ->postJson("/api/v1/finance/earnings-queries/{$ulid}/respond", [
            'decision' => 'resolved',
            'resolution_note' => 'Confirmed a 500 shortfall; issuing an additive correction.',
            'correction' => ['amount_minor' => 500, 'currency' => 'KES', 'reason' => 'Commission shortfall correction.'],
        ])->assertOk();

    $resolved->assertJsonPath('data.status', 'resolved');
    expect($resolved->json('data.resolved_adjustment_id'))->toHaveLength(26);

    // The correction is an additive adjustment; the source ledger is UNCHANGED.
    expect(CompensationAdjustment::query()->where('amount_minor', 500)->count())->toBe(1);
    expect(CommissionLedgerEntry::query()->whereKey($ledger->id)->value('amount_minor'))->toBe($original);
});

it('forbids a personnel from responding to a query and keeps replay idempotent', function (): void {
    $scn = earningsScn();
    $ledger = earnedCommission($scn['branch'], $scn['personnelStaff'], 50000);
    $ulid = test()->actingAs($scn['personnelUser'], 'sanctum')
        ->postJson('/api/v1/personnel/me/earnings-queries', [
            'subject_type' => 'commission_ledger', 'subject_ulid' => $ledger->ulid,
            'query_type' => 'commission_disagreement', 'body' => 'Please review my commission.',
        ])->assertCreated()->json('data.id');

    // Personnel cannot respond (Finance-only route).
    test()->actingAs($scn['personnelUser'], 'sanctum')->withHeaders(idemE())
        ->postJson("/api/v1/finance/earnings-queries/{$ulid}/respond", ['decision' => 'rejected', 'resolution_note' => 'n/a'])
        ->assertForbidden();

    // Finance resolves with a correction; replaying the SAME key does not create a second adjustment.
    $headers = idemE();
    $body = ['decision' => 'resolved', 'resolution_note' => 'Correcting once.', 'correction' => ['amount_minor' => 250, 'currency' => 'KES', 'reason' => 'once']];
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders($headers)->postJson("/api/v1/finance/earnings-queries/{$ulid}/respond", $body)->assertOk();
    test()->actingAs($scn['finance'], 'sanctum')->withHeaders($headers)->postJson("/api/v1/finance/earnings-queries/{$ulid}/respond", $body)->assertOk();

    expect(CompensationAdjustment::query()->where('amount_minor', 250)->count())->toBe(1);
});
