<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-api');

/*
 | Phase 20G Increment 5 — Finance compensation-liability read API + manual adjustment API (Plan §61/§80,
 | §19.3). Merchant scope, masked, server-authoritative totals grouped by currency. Adjustment creation
 | is a financial mutation: compensation.adjustment.create + fresh step-up + Idempotency-Key + high-
 | severity audit; append-only, standalone `manual` (the schema forbids Finance source-linked rows).
 */

/**
 * @return array{merchant: Merchant, branch: MerchantBranch, staff: StaffProfile, finance: User,
 *   hr: User, frontOffice: User}
 */
function compScn(): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $staff = StaffProfile::factory()->create(['merchant_id' => $merchant->id, 'primary_branch_id' => $branch->id]);

    [$finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [$frontOffice] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    return compact('merchant', 'branch', 'staff', 'finance', 'hr', 'frontOffice');
}

/** Seed representative liability facts for one staff/branch. */
function seedLiabilities(array $scn): void
{
    SalaryLedgerEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $scn['staff']->id,
        'entry_type' => 'accrual', 'status' => 'pending', 'amount_minor' => 300000, 'currency' => 'KES',
    ]);
    CommissionLedgerEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $scn['staff']->id,
        'entry_type' => 'earned', 'status' => 'earned', 'amount_minor' => 50000, 'currency' => 'KES',
    ]);
    CompensationAdjustment::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $scn['staff']->id,
        'adjustment_type' => 'manual', 'amount_minor' => -10000, 'currency' => 'KES',
    ]);
}

// --- Liability summary --------------------------------------------------------------------------

it('returns server-derived per-currency liability totals to Finance', function (): void {
    $scn = compScn();
    seedLiabilities($scn);

    $data = test()->actingAs($scn['finance'], 'sanctum')
        ->getJson('/api/v1/compensation/liabilities/summary')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1);
    $kes = $data[0];
    expect($kes['currency'])->toBe('KES')
        ->and($kes['net_salary_liability_minor'])->toBe(300000)
        ->and($kes['net_commission_liability_minor'])->toBe(50000)
        ->and($kes['compensation_adjustment_minor'])->toBe(-10000)
        ->and($kes['combined_net_liability_minor'])->toBe(340000);
});

it('never combines different currencies in the summary', function (): void {
    $scn = compScn();
    SalaryLedgerEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $scn['staff']->id,
        'entry_type' => 'accrual', 'status' => 'pending', 'amount_minor' => 300000, 'currency' => 'KES',
    ]);
    SalaryLedgerEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $scn['staff']->id,
        'entry_type' => 'accrual', 'status' => 'pending', 'amount_minor' => 40000, 'currency' => 'USD',
    ]);

    $data = collect(test()->actingAs($scn['finance'], 'sanctum')
        ->getJson('/api/v1/compensation/liabilities/summary')->assertOk()->json('data'))
        ->keyBy('currency');

    expect($data)->toHaveCount(2);
    expect($data['KES']['net_salary_liability_minor'])->toBe(300000);
    expect($data['USD']['net_salary_liability_minor'])->toBe(40000);
});

it('excludes a paid salary accrual from the net liability', function (): void {
    $scn = compScn();
    SalaryLedgerEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $scn['staff']->id,
        'entry_type' => 'accrual', 'status' => 'paid', 'amount_minor' => 999999, 'currency' => 'KES',
    ]);
    SalaryLedgerEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $scn['staff']->id,
        'entry_type' => 'accrual', 'status' => 'pending', 'amount_minor' => 100000, 'currency' => 'KES',
    ]);

    $kes = collect(test()->actingAs($scn['finance'], 'sanctum')
        ->getJson('/api/v1/compensation/liabilities/summary')->assertOk()->json('data'))->firstWhere('currency', 'KES');

    expect($kes['net_salary_liability_minor'])->toBe(100000); // paid excluded
    expect($kes['gross_salary_accrual_minor'])->toBe(1099999);
});

// --- Liability entries --------------------------------------------------------------------------

it('lists masked liability entries with public ULIDs only', function (): void {
    $scn = compScn();
    seedLiabilities($scn);

    $rows = test()->actingAs($scn['finance'], 'sanctum')
        ->getJson('/api/v1/compensation/liabilities')->assertOk()->json('data');

    expect(count($rows))->toBe(2); // salary accrual + commission earned
    foreach ($rows as $row) {
        expect($row['id'])->toHaveLength(26)
            ->and($row['staff_profile_id'])->toBe($scn['staff']->ulid)
            ->and($row)->not->toHaveKeys(['merchant_id', 'staff_profile_internal_id']);
        expect(in_array($row['liability_type'], ['salary', 'commission'], true))->toBeTrue();
    }
});

it('filters liability entries by liability_type', function (): void {
    $scn = compScn();
    seedLiabilities($scn);

    $rows = test()->actingAs($scn['finance'], 'sanctum')
        ->getJson('/api/v1/compensation/liabilities?liability_type=commission')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['liability_type'])->toBe('commission');
});

// --- Authorization ------------------------------------------------------------------------------

it('denies the liability read to a non-Finance role (HR)', function (): void {
    $scn = compScn();

    test()->actingAs($scn['hr'], 'sanctum')
        ->getJson('/api/v1/compensation/liabilities/summary')->assertForbidden();
    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson('/api/v1/compensation/liabilities')->assertForbidden();
});

it('isolates tenants: a foreign staff filter resolves to 404', function (): void {
    $scn = compScn();
    $otherStaff = StaffProfile::factory()->create(); // different merchant

    test()->actingAs($scn['finance'], 'sanctum')
        ->getJson('/api/v1/compensation/liabilities?staff_profile_ulid='.$otherStaff->ulid)
        ->assertNotFound();
});

// --- Adjustment creation ------------------------------------------------------------------------

it('creates a manual adjustment with fresh step-up + idempotency and writes a high-severity audit', function (): void {
    $scn = compScn();
    confirmedTotp($scn['finance']);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', 'adj-'.Str::random(24))
        ->postJson('/api/v1/compensation/adjustments', [
            'staff_profile_ulid' => $scn['staff']->ulid,
            'amount_minor' => -25000,
            'currency' => 'KES',
            'reason' => 'Overpaid commission clawback (manual correction).',
        ])->assertCreated();

    $response->assertJsonPath('data.amount_minor', -25000)
        ->assertJsonPath('data.adjustment_type', 'manual')
        ->assertJsonPath('data.staff_profile_id', $scn['staff']->ulid);

    $adjustment = CompensationAdjustment::query()->firstOrFail();
    expect($adjustment->branch_id)->toBe($scn['branch']->id) // server-derived from the staff branch
        ->and($adjustment->amount_minor)->toBe(-25000);

    expect(AuditLog::query()->where('action', 'compensation.adjustment.created')->count())->toBe(1);
});

it('is idempotent: the same Idempotency-Key creates exactly one adjustment', function (): void {
    $scn = compScn();
    confirmedTotp($scn['finance']);
    $key = 'adj-'.Str::random(24);
    $body = ['staff_profile_ulid' => $scn['staff']->ulid, 'amount_minor' => 15000, 'currency' => 'KES', 'reason' => 'Bonus top-up adjustment.'];

    test()->statefulMfa(now()->getTimestamp())->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', $key)->postJson('/api/v1/compensation/adjustments', $body)->assertCreated();
    test()->statefulMfa(now()->getTimestamp())->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', $key)->postJson('/api/v1/compensation/adjustments', $body)->assertCreated();

    expect(CompensationAdjustment::query()->count())->toBe(1);
});

it('denies adjustment creation without a fresh MFA step-up', function (): void {
    $scn = compScn();
    confirmedTotp($scn['finance']);
    $stale = now()->subMinutes((int) config('servana.mfa.step_up_window_minutes') + 1)->getTimestamp();

    test()->statefulMfa($stale)->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', 'adj-'.Str::random(24))
        ->postJson('/api/v1/compensation/adjustments', [
            'staff_profile_ulid' => $scn['staff']->ulid, 'amount_minor' => 5000, 'currency' => 'KES', 'reason' => 'test adjustment',
        ])->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');

    expect(CompensationAdjustment::query()->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'compensation.adjustment.created')->count())->toBe(0);
});

it('requires an Idempotency-Key on the adjustment POST', function (): void {
    $scn = compScn();
    confirmedTotp($scn['finance']);

    test()->statefulMfa(now()->getTimestamp())->actingAs($scn['finance'], 'sanctum')
        ->postJson('/api/v1/compensation/adjustments', [
            'staff_profile_ulid' => $scn['staff']->ulid, 'amount_minor' => 5000, 'currency' => 'KES', 'reason' => 'test adjustment',
        ])->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');

    expect(CompensationAdjustment::query()->count())->toBe(0);
});

it('denies adjustment creation to a non-Finance role', function (): void {
    $scn = compScn();
    confirmedTotp($scn['hr']);

    test()->statefulMfa(now()->getTimestamp())->actingAs($scn['hr'], 'sanctum')
        ->withHeader('Idempotency-Key', 'adj-'.Str::random(24))
        ->postJson('/api/v1/compensation/adjustments', [
            'staff_profile_ulid' => $scn['staff']->ulid, 'amount_minor' => 5000, 'currency' => 'KES', 'reason' => 'test adjustment',
        ])->assertForbidden();

    expect(CompensationAdjustment::query()->count())->toBe(0);
});

it('rejects a zero amount, a server-owned field, and a foreign staff profile', function (): void {
    $scn = compScn();
    confirmedTotp($scn['finance']);
    $mfa = fn () => test()->statefulMfa(now()->getTimestamp())->actingAs($scn['finance'], 'sanctum')->withHeader('Idempotency-Key', 'adj-'.Str::random(24));

    // Zero amount.
    $mfa()->postJson('/api/v1/compensation/adjustments', [
        'staff_profile_ulid' => $scn['staff']->ulid, 'amount_minor' => 0, 'currency' => 'KES', 'reason' => 'zero',
    ])->assertStatus(422);

    // Server-owned field.
    $mfa()->postJson('/api/v1/compensation/adjustments', [
        'staff_profile_ulid' => $scn['staff']->ulid, 'amount_minor' => 5000, 'currency' => 'KES', 'reason' => 'x', 'adjustment_type' => 'paid_commission_reversal',
    ])->assertStatus(422);

    // Foreign staff profile (different merchant) → 404, no existence leak.
    $foreign = StaffProfile::factory()->create();
    $mfa()->postJson('/api/v1/compensation/adjustments', [
        'staff_profile_ulid' => $foreign->ulid, 'amount_minor' => 5000, 'currency' => 'KES', 'reason' => 'foreign',
    ])->assertNotFound();

    expect(CompensationAdjustment::query()->count())->toBe(0);
});
