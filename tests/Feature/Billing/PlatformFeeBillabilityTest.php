<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-billability');

beforeEach(fn () => Queue::fake([GenerateReceiptPdf::class]));

/*
 | Phase 20E billability at Finance validation (Plan §51). PostgreSQL 16. paymentScenario()/
 | recordPendingGroup()/validatePaymentGroup()/cashComponent() come from tests/Pest.php. A percentage
 | finalization snapshot is applied to the issued invoice to mimic a percentage-mode finalization.
 */

function applyPercentageSnapshot(Invoice $invoice, array $overrides = []): PlatformFeeConfiguration
{
    $config = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subDay(), 'effective_to' => null,
    ]);

    $invoice->forceFill(array_merge([
        'platform_fee_configuration_id' => $config->id,
        'platform_fee_billing_mode_snapshot' => 'percentage_on_merchant_client_invoice',
        'platform_fee_rate_bps_snapshot' => 250,
        'platform_fee_tier_snapshot' => 'customer_centric',
        'platform_fee_basis_type_snapshot' => 'merchant_client_invoice_service_subtotal',
        'platform_fee_shared_split_snapshot' => null,
        'platform_fee_currency' => 'KES',
        'platform_fee_gross_minor' => 12500, // round_half_up(500000 * 250/10000)
        'platform_fee_client_shifted_minor' => 0,
        'platform_fee_resolved_at' => now(),
    ], $overrides))->save();

    return $config;
}

it('creates no earned entry for a fixed-only invoice validation', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();

    expect(PlatformFeeLedgerEntry::query()->count())->toBe(0);
});

it('creates no earned entry from recording alone (before validation)', function (): void {
    $scn = paymentScenario(500000);
    applyPercentageSnapshot($scn['invoice']);
    recordPendingGroup($scn, [cashComponent(500000)]);

    expect(PlatformFeeLedgerEntry::query()->count())->toBe(0);
});

it('creates one earned/pending entry on a successful full validation', function (): void {
    $scn = paymentScenario(500000);
    applyPercentageSnapshot($scn['invoice']);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);

    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();

    $entries = PlatformFeeLedgerEntry::query()->get();
    expect($entries)->toHaveCount(1);
    $entry = $entries->first();
    expect($entry->entry_type->value)->toBe('earned')
        ->and($entry->status)->toBe(PlatformFeeLedgerStatus::Pending)
        ->and($entry->gross_platform_fee_minor)->toBe(12500)
        ->and($entry->merchant_liability_minor)->toBe(12500)
        ->and($entry->client_shifted_amount_minor)->toBe(0)
        ->and($entry->currency)->toBe('KES')
        ->and($entry->billable_at)->not->toBeNull()
        ->and($entry->source_validation_event_id)->not->toBeNull()
        ->and($entry->source_invoice_id)->toBe($scn['invoice']->id)
        ->and($entry->merchant_id)->toBe($scn['merchant']->id);
});

it('releases liability proportionally across partial validations and captures the residual', function (): void {
    $scn = paymentScenario(500000);
    applyPercentageSnapshot($scn['invoice']);

    // First partial: 250000 → cumulative target round_half_up(12500 * 250000/500000) = 6250.
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(250000)]))->assertCreated();
    // Second partial: 250000 → cumulative target 12500, minus prior 6250 = 6250 residual.
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(250000)]))->assertCreated();

    $entries = PlatformFeeLedgerEntry::query()->orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->sum('gross_platform_fee_minor'))->toBe(12500) // == snapshot total (residual captured)
        ->and($entries[0]->gross_platform_fee_minor)->toBe(6250)
        ->and($entries[1]->gross_platform_fee_minor)->toBe(6250);

    // Unvalidated balance never over-bills: cumulative earned never exceeds the snapshot.
    expect($entries->sum('gross_platform_fee_minor'))->toBeLessThanOrEqual(12500);
});

it('uses only the newly validated amount for a validated_paid_amount basis', function (): void {
    $scn = paymentScenario(500000);
    applyPercentageSnapshot($scn['invoice'], [
        'platform_fee_basis_type_snapshot' => 'validated_paid_amount',
        // gross projection is informational for this basis; the earned fee follows the validated amount.
    ]);

    // First partial 200000 → earned = round_half_up(200000 * 250/10000) = 5000.
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(200000)]))->assertCreated();
    // Second 300000 → earned = round_half_up(300000 * 250/10000) = 7500.
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(300000)]))->assertCreated();

    $entries = PlatformFeeLedgerEntry::query()->orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->gross_platform_fee_minor)->toBe(5000)
        ->and($entries[1]->gross_platform_fee_minor)->toBe(7500)
        ->and($entries->every(fn ($e) => $e->client_shifted_amount_minor === 0))->toBeTrue()
        ->and($entries->every(fn ($e) => $e->merchant_absorbed_amount_minor === $e->gross_platform_fee_minor))->toBeTrue();
});

it('stamps the validation-source identity and stores the immutable snapshot on the entry', function (): void {
    $scn = paymentScenario(500000);
    applyPercentageSnapshot($scn['invoice']);
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(500000)]))->assertCreated();

    $entry = PlatformFeeLedgerEntry::query()->firstOrFail();
    expect($entry->service_fee_tier_snapshot->value)->toBe('customer_centric')
        ->and($entry->fee_basis_type->value)->toBe('merchant_client_invoice_service_subtotal')
        ->and($entry->percentage_rate_snapshot)->toBe(250)
        ->and($entry->effective_configuration_id)->not->toBeNull()
        ->and($entry->idempotency_key)->toContain('earned:');
});
