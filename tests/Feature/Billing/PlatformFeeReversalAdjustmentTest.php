<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PlatformFeeAdjustmentType;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Models\PlatformFeeAdjustment;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Services\RecordPlatformFeeAdjustment;
use App\Domain\Billing\Services\RecordPlatformFeeReversal;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Models\Client;
use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use App\Domain\FinanceOps\Exceptions\FinancialPeriodLockedException;
use App\Domain\Invoicing\Actions\ExecuteInvoiceVoid;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use App\Domain\Refunds\Actions\FinalizeRefund;
use App\Domain\Refunds\Models\Refund;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-reversal');

/*
 | Phase 20E Increment 5B — additive reversals + adjustments through the void/refund/correction workflows
 | (Plan §13.10, §51, §953). PostgreSQL 16. The original earned row is NEVER edited; corrections are
 | append-only (a reversal/adjustment ledger row + a signed platform_fee_adjustments row).
 */

/** @return array{merchant: Merchant, config: PlatformFeeConfiguration, invoice: Invoice, actor: User, entry: PlatformFeeLedgerEntry} */
function feeScenario(int $gross = 12500): array
{
    $merchant = Merchant::factory()->create();
    $sourceInvoice = Invoice::factory()->issued()->create(); // clean nested tenant before binding
    app(TenantContext::class)->bindForJob($merchant);
    $actor = User::factory()->create();
    $config = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subYears(2), 'effective_to' => null,
    ]);
    $entry = PlatformFeeLedgerEntry::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => null,
        'source_invoice_id' => $sourceInvoice->id,
        'entry_type' => 'earned',
        'status' => 'pending',
        'effective_configuration_id' => $config->id,
        'service_fee_tier_snapshot' => 'customer_centric',
        'gross_platform_fee_minor' => $gross,
        'client_shifted_amount_minor' => 0,
        'merchant_absorbed_amount_minor' => $gross,
        'merchant_liability_minor' => $gross,
        'currency' => 'KES',
    ]);

    return ['merchant' => $merchant, 'config' => $config, 'invoice' => $sourceInvoice, 'actor' => $actor, 'entry' => $entry];
}

/** Apply a customer-centric 2.50% finalization snapshot to an issued invoice (gross = subtotal * 250/10000). */
function reversalPctSnapshot(Invoice $invoice, int $gross = 12500): PlatformFeeConfiguration
{
    $config = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subDay(), 'effective_to' => null,
    ]);
    $invoice->forceFill([
        'platform_fee_configuration_id' => $config->id,
        'platform_fee_billing_mode_snapshot' => 'percentage_on_merchant_client_invoice',
        'platform_fee_rate_bps_snapshot' => 250,
        'platform_fee_tier_snapshot' => 'customer_centric',
        'platform_fee_basis_type_snapshot' => 'merchant_client_invoice_service_subtotal',
        'platform_fee_shared_split_snapshot' => null,
        'platform_fee_currency' => 'KES',
        'platform_fee_gross_minor' => $gross,
        'platform_fee_client_shifted_minor' => 0,
        'platform_fee_resolved_at' => now(),
    ])->save();

    return $config;
}

/** A void_pending merchant-client invoice owned by $merchant (explicit FKs; tenant already bound). */
function voidableInvoiceFor(Merchant $merchant): Invoice
{
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $client = Client::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    return Invoice::factory()->voidPending()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
    ]);
}

it('records a full reversal as an additive row and leaves the original earned row unchanged', function (): void {
    $scn = feeScenario(12500);
    $entry = $scn['entry'];

    $adjustment = DB::transaction(fn () => app(RecordPlatformFeeReversal::class)->record(
        $entry, 'Invoice voided', 'src-ref', 'reversal:test:1', $scn['actor'], CarbonImmutable::now('Africa/Nairobi'),
    ));

    // The correction evidence (signed negative) + the additive ledger reversal row.
    expect($adjustment->adjustment_type)->toBe(PlatformFeeAdjustmentType::Reversal)
        ->and($adjustment->amount_minor)->toBe(-12500)
        ->and($adjustment->currency)->toBe('KES');

    $reversalRow = PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->where('reversed_entry_id', $entry->id)->firstOrFail();
    expect($reversalRow->gross_platform_fee_minor)->toBe(12500)
        ->and($reversalRow->status)->toBe(PlatformFeeLedgerStatus::Pending);

    // Original earned monetary fact is immutable; only its status marker moved to reversed.
    $entry->refresh();
    expect($entry->gross_platform_fee_minor)->toBe(12500)
        ->and($entry->status)->toBe(PlatformFeeLedgerStatus::Reversed);
});

it('records a proportional partial_refund adjustment and decrements the reversible balance', function (): void {
    $scn = feeScenario(12500);
    $entry = $scn['entry'];

    $adjustment = DB::transaction(fn () => app(RecordPlatformFeeAdjustment::class)->record(
        $entry, PlatformFeeAdjustmentType::PartialRefund, -6250, 'Partial refund', 'ref', 'adjustment:test:1', $scn['actor'], CarbonImmutable::now('Africa/Nairobi'),
    ));

    expect($adjustment->amount_minor)->toBe(-6250);
    expect($entry->fresh()->status)->toBe(PlatformFeeLedgerStatus::Adjusted)
        ->and($entry->fresh()->gross_platform_fee_minor)->toBe(12500); // unchanged
    // Remaining reversible = 12500 - 6250 = 6250.
    expect(app(RecordPlatformFeeAdjustment::class)->remainingReversible($entry->fresh()))->toBe(6250);
});

it('supports multiple partials until the balance is exhausted then rejects over-reversal (409)', function (): void {
    $scn = feeScenario(10000);
    $entry = $scn['entry'];
    $svc = app(RecordPlatformFeeAdjustment::class);

    DB::transaction(fn () => $svc->record($entry, PlatformFeeAdjustmentType::PartialRefund, -4000, 'p1', null, 'adj:1', $scn['actor'], CarbonImmutable::now()));
    DB::transaction(fn () => $svc->record($entry, PlatformFeeAdjustmentType::PartialRefund, -4000, 'p2', null, 'adj:2', $scn['actor'], CarbonImmutable::now()));

    expect($svc->remainingReversible($entry->fresh()))->toBe(2000);

    // A third reduction exceeding the remaining 2000 is rejected as over-reversal.
    expect(fn () => DB::transaction(fn () => $svc->record($entry, PlatformFeeAdjustmentType::PartialRefund, -2001, 'p3', null, 'adj:3', $scn['actor'], CarbonImmutable::now())))
        ->toThrow(PlatformFeeException::class);

    try {
        DB::transaction(fn () => $svc->record($entry->fresh(), PlatformFeeAdjustmentType::PartialRefund, -5000, 'p4', null, 'adj:4', $scn['actor'], CarbonImmutable::now()));
    } catch (PlatformFeeException $e) {
        expect($e->status())->toBe(409)->and($e->errorCode())->toBe('platform_fee_over_reversal');
    }
});

it('rejects a wrong sign for the adjustment type', function (): void {
    $scn = feeScenario();
    // partial_refund must be negative.
    expect(fn () => DB::transaction(fn () => app(RecordPlatformFeeAdjustment::class)->record(
        $scn['entry'], PlatformFeeAdjustmentType::PartialRefund, 6250, 'bad', null, 'k1', $scn['actor'], CarbonImmutable::now(),
    )))->toThrow(PlatformFeeException::class);
});

it('is idempotent per source correction event (replay returns the same adjustment, no duplicate)', function (): void {
    $scn = feeScenario(12500);
    $entry = $scn['entry'];
    $svc = app(RecordPlatformFeeReversal::class);

    $first = DB::transaction(fn () => $svc->record($entry, 'void', null, 'reversal:dup:1', $scn['actor'], CarbonImmutable::now()));
    $second = DB::transaction(fn () => $svc->record($entry->fresh(), 'void', null, 'reversal:dup:1', $scn['actor'], CarbonImmutable::now()));

    expect($second->id)->toBe($first->id)
        ->and(PlatformFeeAdjustment::query()->where('platform_fee_ledger_entry_id', $entry->id)->count())->toBe(1)
        ->and(PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(1);
});

it('a rolled-back correction writes no adjustment, ledger row, or success audit', function (): void {
    $scn = feeScenario(12500);
    $entry = $scn['entry'];
    $auditBefore = DB::table('audit_logs')->count();

    try {
        DB::transaction(function () use ($entry, $scn): void {
            app(RecordPlatformFeeReversal::class)->record($entry, 'void', null, 'reversal:rb:1', $scn['actor'], CarbonImmutable::now());
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(PlatformFeeAdjustment::query()->count())->toBe(0)
        ->and(PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(0)
        ->and($entry->fresh()->status)->toBe(PlatformFeeLedgerStatus::Pending)
        ->and(DB::table('audit_logs')->count())->toBe($auditBefore);
});

it('void of a merchant-client invoice fully reverses its earned platform fee (hook)', function (): void {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->bindForJob($merchant);
    $invoice = voidableInvoiceFor($merchant);
    $finance = User::factory()->create();
    MerchantUser::factory()->create(['user_id' => $finance->id, 'merchant_id' => $merchant->id, 'role' => MerchantUserRole::Finance]);
    $config = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subYears(2), 'effective_to' => null,
    ]);
    $entry = PlatformFeeLedgerEntry::factory()->create([
        'merchant_id' => $merchant->id, 'branch_id' => null, 'source_invoice_id' => $invoice->id,
        'entry_type' => 'earned', 'status' => 'pending', 'effective_configuration_id' => $config->id,
        'service_fee_tier_snapshot' => 'customer_centric', 'gross_platform_fee_minor' => 12500,
        'client_shifted_amount_minor' => 0, 'merchant_absorbed_amount_minor' => 12500,
        'merchant_liability_minor' => 12500, 'currency' => 'KES',
    ]);

    app(ExecuteInvoiceVoid::class)->handle($invoice->fresh(), $finance);

    expect(PlatformFeeAdjustment::query()->where('adjustment_type', 'reversal')->where('amount_minor', -12500)->count())->toBe(1)
        ->and($entry->fresh()->status)->toBe(PlatformFeeLedgerStatus::Reversed)
        ->and($entry->fresh()->gross_platform_fee_minor)->toBe(12500);
});

it('a locked period blocks the void and creates no platform-fee correction', function (): void {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->bindForJob($merchant);
    $invoice = voidableInvoiceFor($merchant);
    $finance = User::factory()->create();
    MerchantUser::factory()->create(['user_id' => $finance->id, 'merchant_id' => $merchant->id, 'role' => MerchantUserRole::Finance]);
    $config = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subYears(2), 'effective_to' => null,
    ]);
    PlatformFeeLedgerEntry::factory()->create([
        'merchant_id' => $merchant->id, 'branch_id' => null, 'source_invoice_id' => $invoice->id,
        'entry_type' => 'earned', 'status' => 'pending', 'effective_configuration_id' => $config->id,
        'service_fee_tier_snapshot' => 'customer_centric', 'gross_platform_fee_minor' => 12500,
        'client_shifted_amount_minor' => 0, 'merchant_absorbed_amount_minor' => 12500,
        'merchant_liability_minor' => 12500, 'currency' => 'KES',
    ]);

    app()->bind(PeriodLockRepository::class, fn (): PeriodLockRepository => new class implements PeriodLockRepository
    {
        public function isLocked(int $merchantId, ?int $branchId, CarbonInterface $businessDate): bool
        {
            return true;
        }
    });

    expect(fn () => app(ExecuteInvoiceVoid::class)->handle($invoice->fresh(), $finance))
        ->toThrow(FinancialPeriodLockedException::class);

    expect(PlatformFeeAdjustment::query()->count())->toBe(0)
        ->and(PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->count())->toBe(0);
});

it('a full refund fully reverses the earned platform fee (FinalizeRefund hook)', function (): void {
    Queue::fake([GenerateReceiptPdf::class]);
    $scn = paymentScenario(500000);
    reversalPctSnapshot($scn['invoice']);
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(500000)]))->assertCreated();

    $entry = PlatformFeeLedgerEntry::query()->where('source_invoice_id', $scn['invoice']->id)->firstOrFail();
    expect($entry->gross_platform_fee_minor)->toBe(12500);

    app(TenantContext::class)->bindForJob($scn['merchant']);
    // RequestRefund would move the invoice into refund_pending; simulate that precondition.
    Invoice::query()->whereKey($scn['invoice']->id)->update(['status' => 'refund_pending']);
    $component = PaymentRecord::query()->where('invoice_id', $scn['invoice']->id)->firstOrFail();
    $refund = Refund::factory()->approved()->create([
        'payment_record_id' => $component->id,
        'amount_minor' => 500000,
        'currency' => 'KES',
        'requested_by' => $scn['frontOffice']->id,
    ]);

    app(FinalizeRefund::class)->handle($refund, $scn['finance']);

    expect(PlatformFeeAdjustment::query()->where('adjustment_type', 'reversal')->where('amount_minor', -12500)->count())->toBe(1)
        ->and($entry->fresh()->status)->toBe(PlatformFeeLedgerStatus::Reversed)
        ->and($entry->fresh()->gross_platform_fee_minor)->toBe(12500);
})->group('phase20e-refund-hook');

it('a partial refund creates a proportional partial_refund adjustment (FinalizeRefund hook)', function (): void {
    Queue::fake([GenerateReceiptPdf::class]);
    $scn = paymentScenario(500000);
    reversalPctSnapshot($scn['invoice']);
    // Validate only 250000 (invoice becomes partially_paid) → earned = round_half_up(12500 * 250000/500000) = 6250.
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(250000)]))->assertCreated();

    $entry = PlatformFeeLedgerEntry::query()->where('source_invoice_id', $scn['invoice']->id)->firstOrFail();
    expect($entry->gross_platform_fee_minor)->toBe(6250);

    app(TenantContext::class)->bindForJob($scn['merchant']);
    // RequestRefund would move the invoice into refund_pending; simulate that precondition.
    Invoice::query()->whereKey($scn['invoice']->id)->update(['status' => 'refund_pending']);
    $component = PaymentRecord::query()->where('invoice_id', $scn['invoice']->id)->firstOrFail();
    $refund = Refund::factory()->approved()->create([
        'payment_record_id' => $component->id,
        'amount_minor' => 100000, // refund part of the 250000 validated → invoice stays partially_paid
        'currency' => 'KES',
        'requested_by' => $scn['frontOffice']->id,
    ]);

    app(FinalizeRefund::class)->handle($refund, $scn['finance']);

    // round_half_up(6250 * 100000 / 250000) = 2500.
    expect(PlatformFeeAdjustment::query()->where('adjustment_type', 'partial_refund')->where('amount_minor', -2500)->count())->toBe(1)
        ->and($entry->fresh()->status)->toBe(PlatformFeeLedgerStatus::Adjusted)
        ->and($entry->fresh()->gross_platform_fee_minor)->toBe(6250);
})->group('phase20e-refund-hook');
