<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('ui12', 'finance-experience');

it('returns assigned-branch Finance facts with currency-separated money and truthful gates', function (): void {
    $scenario = paymentScenario(500_000);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scenario['merchant']->id]);
    [$otherFrontOffice] = branchStaff($scenario['merchant'], $otherBranch, MerchantUserRole::FrontOffice);
    $foreignInvoice = Invoice::factory()->issued(900_000)->create([
        'merchant_id' => $scenario['merchant']->id,
        'branch_id' => $otherBranch->id,
    ]);

    recordPaymentGroup($scenario['frontOffice'], $scenario['invoice']->ulid, [cashComponent(125_000)])
        ->assertCreated();
    recordPaymentGroup($otherFrontOffice, $foreignInvoice->ulid, [cashComponent(225_000)])
        ->assertCreated();

    $response = $this->actingAs($scenario['finance'], 'sanctum')
        ->getJson('/api/v1/finance/workspace')
        ->assertOk()
        ->assertJsonCount(1, 'data.overview.branch_context.branches')
        ->assertJsonPath('data.overview.branch_context.branches.0.id', $scenario['branch']->ulid)
        ->assertJsonPath('data.overview.payments.pending_validation', 1)
        ->assertJsonPath('data.overview.payments.pending_recorded.0.amount', 125_000)
        ->assertJsonPath('data.overview.payments.pending_recorded.0.currency', 'KES')
        ->assertJsonPath('data.overview.subscription.available', false)
        ->assertJsonPath('data.overview.reports.available', false)
        ->assertJsonPath('data.overview.notifications.available', false);

    expect((string) $response->getContent())
        ->toContain('External Gate W')
        ->not->toContain($otherBranch->ulid)
        ->not->toContain($foreignInvoice->ulid)
        ->not->toContain('provider_status')
        ->not->toContain('notification_count');

    $this->actingAs($scenario['frontOffice'], 'sanctum')
        ->getJson('/api/v1/finance/workspace')
        ->assertForbidden();
});

it('serves a paginated assigned-branch duplicate queue without raw references', function (): void {
    $scenario = paymentScenario(500_000);
    recordPaymentGroup($scenario['frontOffice'], $scenario['invoice']->ulid, [
        referencedComponent(100_000, reference: 'QGX7YT1ABC'),
    ])->assertCreated();
    recordPaymentGroup($scenario['frontOffice'], $scenario['invoice']->ulid, [
        referencedComponent(100_000, reference: 'QGX7YT1ABC'),
    ])->assertStatus(409);

    $check = PaymentReferenceCheck::query()
        ->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)
        ->firstOrFail();

    $response = $this->actingAs($scenario['finance'], 'sanctum')
        ->getJson('/api/v1/finance/duplicate-references?method=mpesa_offline&per_page=20&sort=-checked_at')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $check->ulid)
        ->assertJsonPath('data.0.match_type', 'exact_normalized_reference')
        ->assertJsonPath('data.0.risk', 'high')
        ->assertJsonPath('data.0.can_override', true);

    expect((string) $response->json('data.0.reference_masked'))->toContain('••••');
    expect((string) $response->getContent())
        ->not->toContain('QGX7YT1ABC')
        ->not->toContain('reference_normalized')
        ->not->toContain('reference_display_encrypted');

    $this->actingAs($scenario['finance'], 'sanctum')
        ->getJson('/api/v1/finance/duplicate-references?per_page=101')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

it('excludes foreign assigned branches from the duplicate review queue', function (): void {
    $scenario = paymentScenario(500_000);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scenario['merchant']->id]);
    [$otherFrontOffice] = branchStaff($scenario['merchant'], $otherBranch, MerchantUserRole::FrontOffice);
    $foreignInvoice = Invoice::factory()->issued(500_000)->create([
        'merchant_id' => $scenario['merchant']->id,
        'branch_id' => $otherBranch->id,
    ]);

    recordPaymentGroup($otherFrontOffice, $foreignInvoice->ulid, [
        referencedComponent(100_000, reference: 'SBR8XQ2DEF'),
    ])->assertCreated();
    recordPaymentGroup($otherFrontOffice, $foreignInvoice->ulid, [
        referencedComponent(100_000, reference: 'SBR8XQ2DEF'),
    ])->assertStatus(409);

    $this->actingAs($scenario['finance'], 'sanctum')
        ->getJson('/api/v1/finance/duplicate-references')
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');
});

it('returns a server-owned partial and split balance waterfall scoped to assigned branches', function (): void {
    $scenario = paymentScenario(500_000);
    recordPaymentGroup($scenario['frontOffice'], $scenario['invoice']->ulid, [cashComponent(100_000)])
        ->assertCreated();
    recordPaymentGroup($scenario['frontOffice'], $scenario['invoice']->ulid, [
        cashComponent(50_000),
        referencedComponent(75_000, reference: 'RKP4NZ9GHI'),
    ])->assertCreated();

    $response = $this->actingAs($scenario['finance'], 'sanctum')
        ->getJson('/api/v1/finance/partial-split-payments?per_page=20&sort=-created_at')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.invoice.id', $scenario['invoice']->ulid)
        ->assertJsonPath('data.0.balance.total.amount', 500_000)
        ->assertJsonPath('data.0.balance.validated.amount', 0)
        ->assertJsonPath('data.0.balance.pending_recorded.amount', 225_000)
        ->assertJsonPath('data.0.balance.remaining.amount', 500_000)
        ->assertJsonPath('data.0.group_count', 2)
        ->assertJsonPath('data.0.has_multiple_groups', true)
        ->assertJsonPath('data.0.has_multi_method_group', true);

    expect((string) $response->getContent())
        ->not->toContain('RKP4NZ9GHI')
        ->not->toContain('reference_normalized');

    $this->actingAs($scenario['finance'], 'sanctum')
        ->getJson('/api/v1/finance/partial-split-payments?status=bogus')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});
