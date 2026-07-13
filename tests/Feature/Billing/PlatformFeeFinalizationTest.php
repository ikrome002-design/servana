<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Invoicing\Actions\CreateInvoiceDraft;
use App\Domain\Invoicing\Actions\FinalizeInvoice;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\ServiceFeeTier;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-finalization');

/*
 | Phase 20E finalization integration (Plan §51, §52). PostgreSQL 16. invoiceScenario()/completedSessionFor()
 | come from tests/Pest.php. Default: one service @ 500000 (subtotal = total = 500000), currency KES.
 */

function seedPercentageMode(): void
{
    PlatformBillingSettings::factory()->create([
        'billing_mode' => 'percentage_on_merchant_client_invoice',
        'effective_from' => now()->subDay(),
    ]);
}

function finalizeFresh(array $scn): Invoice
{
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']);

    return app(FinalizeInvoice::class)->handle($draft, $scn['actor']);
}

it('is a true no-op in fixed-only mode (no snapshot, no shifted total, no ledger row)', function (): void {
    $scn = invoiceScenario();
    $issued = finalizeFresh($scn);

    expect($issued->total_minor)->toBe(500000)
        ->and($issued->platform_fee_configuration_id)->toBeNull()
        ->and($issued->platform_fee_gross_minor)->toBeNull()
        ->and($issued->platform_fee_tier_snapshot)->toBeNull()
        ->and(PlatformFeeLedgerEntry::query()->count())->toBe(0)
        ->and($issued->items()->first()->platform_fee_item_gross_minor)->toBeNull();
});

it('snapshots a customer-centric percentage fee without changing the invoice total', function (): void {
    seedPercentageMode();
    PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subDay(), 'effective_to' => null,
    ]);
    $scn = invoiceScenario();

    $issued = finalizeFresh($scn);

    // gross = round_half_up(500000 * 250 / 10000) = 12500; customer_centric shifts 0.
    expect($issued->total_minor)->toBe(500000)
        ->and($issued->platform_fee_gross_minor)->toBe(12500)
        ->and($issued->platform_fee_client_shifted_minor)->toBe(0)
        ->and($issued->platform_fee_tier_snapshot)->toBe('customer_centric')
        ->and($issued->platform_fee_rate_bps_snapshot)->toBe(250)
        ->and($issued->platform_fee_configuration_id)->not->toBeNull()
        // Ledger earning happens at validation, never at finalization.
        ->and(PlatformFeeLedgerEntry::query()->count())->toBe(0);
});

it('adds the full fee to the invoice total for business-centric', function (): void {
    seedPercentageMode();
    PlatformFeeConfiguration::factory()->percentage(250)->businessCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subDay(), 'effective_to' => null,
    ]);
    $scn = invoiceScenario();

    $issued = finalizeFresh($scn);

    expect($issued->platform_fee_gross_minor)->toBe(12500)
        ->and($issued->platform_fee_client_shifted_minor)->toBe(12500)
        ->and($issued->total_minor)->toBe(512500) // 500000 + 12500
        ->and($issued->platform_fee_tier_snapshot)->toBe('business_centric');
});

it('adds the configured share to the invoice total for a shared tier', function (): void {
    seedPercentageMode();
    PlatformFeeConfiguration::factory()->percentage(250)->shared(5000)->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subDay(), 'effective_to' => null,
    ]);
    $scn = invoiceScenario();

    $issued = finalizeFresh($scn);

    // shifted = round_half_up(12500 * 5000/10000) = 6250.
    expect($issued->platform_fee_gross_minor)->toBe(12500)
        ->and($issued->platform_fee_client_shifted_minor)->toBe(6250)
        ->and($issued->total_minor)->toBe(506250)
        ->and($issued->platform_fee_shared_split_snapshot)->toBe(5000);
});

it('keeps merchant liability equal to the full gross fee in every tier', function (): void {
    seedPercentageMode();
    PlatformFeeConfiguration::factory()->percentage(250)->businessCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subDay(), 'effective_to' => null,
    ]);
    $scn = invoiceScenario();
    $issued = finalizeFresh($scn);

    // Client-shifted + merchant-absorbed reconcile to gross at item level (merchant still owes full gross).
    $item = $issued->items()->first();
    expect($item->platform_fee_item_client_shifted_minor + $item->platform_fee_item_absorbed_minor)
        ->toBe($item->platform_fee_item_gross_minor)
        ->and($item->platform_fee_item_gross_minor)->toBe(12500);
});

it('fails closed when a percentage mode is active but no configuration exists (no number consumed)', function (): void {
    seedPercentageMode(); // percentage mode, but NO active configuration seeded
    $scn = invoiceScenario();
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']);

    expect(fn () => app(FinalizeInvoice::class)->handle($draft, $scn['actor']))
        ->toThrow(PlatformFeeException::class);

    $reloaded = Invoice::query()->whereKey($draft->id)->firstOrFail();
    expect($reloaded->status)->toBe(InvoiceStatus::Draft)
        ->and($reloaded->invoice_number)->toBeNull()
        ->and($reloaded->platform_fee_configuration_id)->toBeNull();
});

it('accepts validated_paid_amount with customer_centric and shifts zero to the client', function (): void {
    seedPercentageMode();
    PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()
        ->basis(PlatformFeeBasisType::ValidatedPaidAmount)->active()->create([
            'currency' => 'KES', 'effective_from' => today()->subDay(), 'effective_to' => null,
        ]);
    $scn = invoiceScenario();

    $issued = finalizeFresh($scn);

    expect($issued->total_minor)->toBe(500000) // customer_centric shifts nothing
        ->and($issued->platform_fee_client_shifted_minor)->toBe(0)
        ->and($issued->platform_fee_basis_type_snapshot)->toBe('validated_paid_amount');
});

it('fails closed (Gate 4.2) when validated_paid_amount resolves to a non-customer-centric merchant override', function (): void {
    seedPercentageMode();
    // Config default is customer_centric + validated_paid_amount (DB-valid), but the merchant overrides
    // the resolved tier to business_centric → the resolved-tier guard must fail closed.
    PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()
        ->basis(PlatformFeeBasisType::ValidatedPaidAmount)->active()->create([
            'currency' => 'KES', 'effective_from' => today()->subDay(), 'effective_to' => null,
        ]);
    $scn = invoiceScenario();
    Merchant::query()->whereKey($scn['merchantId'])->update(['service_fee_tier' => ServiceFeeTier::BusinessCentric->value]);

    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']);

    expect(fn () => app(FinalizeInvoice::class)->handle($draft, $scn['actor']))
        ->toThrow(PlatformFeeException::class);

    expect(Invoice::query()->whereKey($draft->id)->firstOrFail()->invoice_number)->toBeNull();
});

it('does not recalculate an issued percentage invoice after a later config change', function (): void {
    seedPercentageMode();
    PlatformFeeConfiguration::factory()->percentage(250)->businessCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subDay(), 'effective_to' => null,
    ]);
    $scn = invoiceScenario();
    $issued = finalizeFresh($scn);
    $snapshotTotal = $issued->total_minor;

    // A later configuration change must never recalculate the issued invoice.
    PlatformFeeConfiguration::query()->update(['status' => 'superseded']);

    expect(Invoice::query()->whereKey($issued->id)->firstOrFail()->total_minor)->toBe($snapshotTotal);
});
