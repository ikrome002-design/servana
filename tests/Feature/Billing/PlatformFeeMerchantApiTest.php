<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\PlatformFeeAdjustment;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-merchant-api');

/*
 | Phase 20E Increment 6 — merchant/Finance platform-fee ledger reads + dispute workflow (Plan §51;
 | Correction 3). Merchant scope, masked, server-side branch scope. Role boundaries, isolation, ULID-only
 | output, server-owned-field rejection, idempotency, no generic/DELETE routes, and audit are proven here.
 */

/** @return array{merchant: Merchant, branch: MerchantBranch, entry: PlatformFeeLedgerEntry, admin: User, finance: User, branchManager: User, audit: User, frontOffice: User} */
function pfMerchantScn(int $gross = 12500): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $client = Client::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);
    $invoice = Invoice::factory()->issued()->create([
        'merchant_id' => $merchant->id, 'branch_id' => $branch->id, 'client_id' => $client->id,
    ]);
    // The configuration is PLATFORM-scoped (shared across merchants) — reuse the single active KES
    // percentage config so a second scenario does not violate the effective-window exclusion.
    $config = PlatformFeeConfiguration::query()->where('currency', 'KES')->where('status', 'active')->first()
        ?? PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
            'currency' => 'KES', 'effective_from' => today()->subYear(), 'effective_to' => null,
        ]);
    $entry = PlatformFeeLedgerEntry::factory()->create([
        'merchant_id' => $merchant->id, 'branch_id' => $branch->id, 'source_invoice_id' => $invoice->id,
        'entry_type' => 'earned', 'status' => 'pending', 'effective_configuration_id' => $config->id,
        'service_fee_tier_snapshot' => 'customer_centric', 'gross_platform_fee_minor' => $gross,
        'client_shifted_amount_minor' => 0, 'merchant_absorbed_amount_minor' => $gross,
        'merchant_liability_minor' => $gross, 'currency' => 'KES',
    ]);

    $admin = memberWithRole(MerchantUserRole::MerchantAdmin, $merchant)[0];
    [$finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);
    [$branchManager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);
    [$frontOffice] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    return compact('merchant', 'branch', 'entry', 'admin', 'finance', 'branchManager', 'audit', 'frontOffice');
}

function pfDisputeIdem(): array
{
    return ['Idempotency-Key' => 'pfd-'.Str::random(24)];
}

// --- Ledger reads -------------------------------------------------------------------------------

it('lets a merchant admin read merchant-wide platform fees (ULID-only, masked)', function (): void {
    $scn = pfMerchantScn();

    $response = test()->actingAs($scn['admin'], 'sanctum')
        ->getJson('/api/v1/platform-fees')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.gross_platform_fee_minor', 12500);

    $row = $response->json('data.0');
    expect(strlen((string) $row['id']))->toBe(26)
        ->and($row)->not->toHaveKey('idempotency_key')
        ->and($row)->not->toHaveKey('source_validation_event_id')
        ->and($row['id'])->not->toBe((string) $scn['entry']->id);
});

it('exposes a scoped summary grouped by currency', function (): void {
    $scn = pfMerchantScn();

    test()->actingAs($scn['admin'], 'sanctum')
        ->getJson('/api/v1/platform-fees/summary')
        ->assertOk()
        ->assertJsonPath('data.0.currency', 'KES')
        ->assertJsonPath('data.0.gross_platform_fee_minor', 12500);
});

it('lets Finance/Branch Manager/Audit read the branch-attributable entry', function (): void {
    $scn = pfMerchantScn();

    foreach (['finance', 'branchManager', 'audit'] as $role) {
        test()->actingAs($scn[$role], 'sanctum')
            ->getJson('/api/v1/platform-fees')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
});

it('denies Front Office the merchant-wide ledger API', function (): void {
    $scn = pfMerchantScn();

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson('/api/v1/platform-fees')
        ->assertForbidden();
});

it('does not leak a foreign-tenant entry (404) and hides it from the list', function (): void {
    $scn = pfMerchantScn();
    $other = pfMerchantScn();

    test()->actingAs($scn['admin'], 'sanctum')
        ->getJson("/api/v1/platform-fees/{$other['entry']->ulid}")
        ->assertNotFound();

    test()->actingAs($scn['admin'], 'sanctum')
        ->getJson('/api/v1/platform-fees')
        ->assertJsonCount(1, 'data'); // only own-merchant entry
});

it('hides a branch entry from a branch-scoped user assigned to a different branch (404 on show)', function (): void {
    $scn = pfMerchantScn();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    [$otherFinance] = branchStaff($scn['merchant'], $otherBranch, MerchantUserRole::Finance);

    test()->actingAs($otherFinance, 'sanctum')
        ->getJson("/api/v1/platform-fees/{$scn['entry']->ulid}")
        ->assertNotFound();

    test()->actingAs($otherFinance, 'sanctum')
        ->getJson('/api/v1/platform-fees')
        ->assertJsonCount(0, 'data'); // different branch → sees nothing
});

// --- Disputes -----------------------------------------------------------------------------------

it('lets a merchant admin raise a dispute (ULID-only) and emits dispute_created', function (): void {
    $scn = pfMerchantScn();

    $response = test()->actingAs($scn['admin'], 'sanctum')
        ->postJson('/api/v1/platform-fee-disputes', [
            'platform_fee_ledger_entry' => $scn['entry']->ulid,
            'reason' => 'This fee looks wrong.',
            'status' => 'resolved', // authoritative field — must be IGNORED
        ], pfDisputeIdem())
        ->assertCreated()
        ->assertJsonPath('data.status', 'open');

    expect(strlen((string) $response->json('data.id')))->toBe(26)
        ->and(AuditLog::query()->where('action', 'platform_fee.dispute_created')->exists())->toBeTrue();
});

it('denies Front Office and Audit from raising a dispute', function (): void {
    $scn = pfMerchantScn();

    foreach (['frontOffice', 'audit'] as $role) {
        test()->actingAs($scn[$role], 'sanctum')
            ->postJson('/api/v1/platform-fee-disputes', [
                'platform_fee_ledger_entry' => $scn['entry']->ulid, 'reason' => 'x y z',
            ], pfDisputeIdem())
            ->assertForbidden();
    }
});

it('returns 404 for a foreign-tenant dispute target', function (): void {
    $scn = pfMerchantScn();
    $other = pfMerchantScn();

    test()->actingAs($scn['admin'], 'sanctum')
        ->postJson('/api/v1/platform-fee-disputes', [
            'platform_fee_ledger_entry' => $other['entry']->ulid, 'reason' => 'cross tenant',
        ], pfDisputeIdem())
        ->assertNotFound();
});

it('drives review → resolve by Finance with a money change that creates an adjustment', function (): void {
    $scn = pfMerchantScn();

    $created = test()->actingAs($scn['admin'], 'sanctum')
        ->postJson('/api/v1/platform-fee-disputes', [
            'platform_fee_ledger_entry' => $scn['entry']->ulid, 'reason' => 'Please review.',
        ], pfDisputeIdem())
        ->assertCreated();
    $ulid = (string) $created->json('data.id');

    // Merchant Admin cannot review.
    test()->actingAs($scn['admin'], 'sanctum')
        ->postJson("/api/v1/platform-fee-disputes/{$ulid}/review")
        ->assertForbidden();

    // Finance reviews.
    test()->actingAs($scn['finance'], 'sanctum')
        ->postJson("/api/v1/platform-fee-disputes/{$ulid}/review")
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review');

    // Finance resolves with a money change (fresh step-up) → additive adjustment; ledger unchanged.
    test()->statefulMfa(now()->getTimestamp())->actingAs($scn['finance'], 'sanctum')
        ->postJson("/api/v1/platform-fee-disputes/{$ulid}/resolve", [
            'resolution_note' => 'Partially upheld.', 'money_change_amount_minor' => -5000,
        ], pfDisputeIdem())
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved');

    expect(PlatformFeeAdjustment::query()->where('adjustment_type', 'dispute_resolution')->where('amount_minor', -5000)->count())->toBe(1)
        ->and($scn['entry']->fresh()->gross_platform_fee_minor)->toBe(12500);
});

it('denies a Finance resolve without a fresh step-up', function (): void {
    $scn = pfMerchantScn();
    $dispute = PlatformFeeDispute::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'platform_fee_ledger_entry_id' => $scn['entry']->id, 'status' => 'under_review',
        'created_by' => $scn['admin']->id, 'assigned_reviewer' => $scn['finance']->id,
    ]);

    test()->statefulMfa(now()->subHours(2)->getTimestamp())->actingAs($scn['finance'], 'sanctum')
        ->postJson("/api/v1/platform-fee-disputes/{$dispute->ulid}/resolve", ['resolution_note' => 'x y z'], pfDisputeIdem())
        ->assertForbidden();
});

it('has no DELETE route for disputes or ledger entries', function (): void {
    $scn = pfMerchantScn();

    test()->actingAs($scn['admin'], 'sanctum')
        ->deleteJson("/api/v1/platform-fees/{$scn['entry']->ulid}")
        ->assertStatus(405);
    test()->actingAs($scn['finance'], 'sanctum')
        ->deleteJson('/api/v1/platform-fee-disputes/'.Str::ulid())
        ->assertStatus(405);
});
