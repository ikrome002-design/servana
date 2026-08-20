<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('ui13', 'front-office-experience');

it('returns a server-owned assigned-branch Front Office workspace without checker authority', function (): void {
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

    $response = $this->actingAs($scenario['frontOffice'], 'sanctum')
        ->getJson('/api/v1/front-office/workspace')
        ->assertOk()
        ->assertJsonPath('data.overview.branch.id', $scenario['branch']->ulid)
        ->assertJsonPath('data.overview.payments.pending_validation', 1)
        ->assertJsonPath('data.overview.subscription.available', false)
        ->assertJsonPath('data.overview.notifications.available', false);

    expect((string) $response->getContent())
        ->not->toContain($otherBranch->ulid)
        ->not->toContain($foreignInvoice->ulid)
        ->not->toContain('validate')
        ->not->toContain('duplicate_override')
        ->not->toContain('provider');

    $this->actingAs($scenario['finance'], 'sanctum')
        ->getJson('/api/v1/front-office/workspace')
        ->assertForbidden();
});

it('returns paginated branch activity as a narrow operational projection', function (): void {
    $scenario = paymentScenario(500_000);
    recordPaymentGroup($scenario['frontOffice'], $scenario['invoice']->ulid, [cashComponent(100_000)])
        ->assertCreated();

    $response = $this->actingAs($scenario['frontOffice'], 'sanctum')
        ->getJson('/api/v1/front-office/activity?domain=billing&per_page=20&sort=-created_at')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.domain', 'billing')
        ->assertJsonPath('data.0.action', 'customer_payment.recorded');

    expect((string) $response->getContent())
        ->not->toContain('hash')
        ->not->toContain('ip_address')
        ->not->toContain('correlation_id')
        ->not->toContain('context');

    $this->actingAs($scenario['frontOffice'], 'sanctum')
        ->getJson('/api/v1/front-office/activity?domain=finance')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

it('returns maker-safe payment and receipt status without Finance controls or raw references', function (): void {
    $scenario = paymentScenario(500_000);
    recordPaymentGroup($scenario['frontOffice'], $scenario['invoice']->ulid, [
        referencedComponent(100_000, reference: 'QGX7YT1ABC'),
    ])->assertCreated();

    $response = $this->actingAs($scenario['frontOffice'], 'sanctum')
        ->getJson('/api/v1/front-office/payment-status?per_page=20&sort=-recorded_at')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.invoice.id', $scenario['invoice']->ulid)
        ->assertJsonPath('data.0.status', 'pending_validation')
        ->assertJsonPath('data.0.receipt.ready', false);

    expect((string) $response->getContent())
        ->not->toContain('QGX7YT1ABC')
        ->not->toContain('validate')
        ->not->toContain('reject')
        ->not->toContain('override')
        ->not->toContain('reissue');

    $this->actingAs($scenario['finance'], 'sanctum')
        ->getJson('/api/v1/front-office/payment-status')
        ->assertForbidden();
});
