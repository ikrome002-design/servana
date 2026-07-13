<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Enums\PlatformFeeAdjustmentType;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Enums\SubscriptionInvoiceItemType;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Services\RecordPlatformFeeAdjustment;
use App\Domain\Billing\Services\RecordPlatformFeeReversal;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-correction-aggregation');

/*
 | Phase 20E backend closure — future-cycle aggregation of pending platform-fee CORRECTIONS
 | (reversal/adjustment of already-invoiced fees) into a signed `adjustment` subscription-invoice line
 | (Plan §13.10, §51, §953; ADR-005; commission carry-forward precedent §2305). PostgreSQL 16. The
 | original earned fact, the issued invoice, and the platform_fee_rollup line are NEVER mutated; a
 | negative correction can never drive the invoice total below zero (residual carries forward); no Wallet
 | credit is created (that is Phase 20D-W).
 */

/** @return array{0:Merchant,1:MerchantSubscription,2:PlatformFeeConfiguration,3:Invoice} */
function corrScenario(int $planPrice = 500000): array
{
    PlatformBillingSettings::factory()->create([
        'billing_mode' => BillingMode::FixedAmount,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);

    $merchant = Merchant::factory()->create();
    $sourceInvoice = Invoice::factory()->issued()->create();
    app(TenantContext::class)->bindForJob($merchant);
    $plan = SubscriptionPlan::factory()->create();
    $price = SubscriptionPlanPrice::factory()->create([
        'plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES', 'amount_minor' => $planPrice,
    ]);
    $sub = MerchantSubscription::factory()->forMerchant($merchant)->status(S::Active)->create([
        'plan_id' => $plan->id, 'price_id' => $price->id, 'billing_interval' => 'monthly',
        'current_period_start' => '2026-07-01', 'current_period_end' => '2026-08-01',
    ]);
    $config = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subYears(2), 'effective_to' => null,
    ]);

    return [$merchant, $sub, $config, $sourceInvoice];
}

/** An earned/pending platform-fee ledger entry for $merchant billable at $billableAt (Nairobi wall clock). */
function corrEarned(Merchant $merchant, PlatformFeeConfiguration $config, Invoice $sourceInvoice, int $gross, string $billableAt, string $currency = 'KES'): PlatformFeeLedgerEntry
{
    return PlatformFeeLedgerEntry::factory()->create([
        'merchant_id' => $merchant->id, 'branch_id' => null, 'source_invoice_id' => $sourceInvoice->id,
        'source_invoice_item_id' => null, 'entry_type' => 'earned', 'status' => 'pending',
        'effective_configuration_id' => $config->id, 'service_fee_tier_snapshot' => 'customer_centric',
        'fee_basis_type' => 'merchant_client_invoice_service_subtotal', 'percentage_rate_snapshot' => 250,
        'shared_split_snapshot' => null, 'gross_platform_fee_minor' => $gross, 'client_shifted_amount_minor' => 0,
        'merchant_absorbed_amount_minor' => $gross, 'merchant_liability_minor' => $gross, 'currency' => $currency,
        'subscription_invoice_item_id' => null,
        'billable_at' => CarbonImmutable::parse($billableAt, 'Africa/Nairobi')->utc(),
    ]);
}

/** Issue the invoice for a specific period by moving the subscription's current period. */
function issueCycle(MerchantSubscription $sub, string $start, string $end): SubscriptionInvoice
{
    $sub->forceFill(['current_period_start' => $start, 'current_period_end' => $end])->save();

    return app(IssueSubscriptionInvoice::class)->handle($sub->fresh(), User::factory()->create());
}

function reverseEntry(PlatformFeeLedgerEntry $entry, string $businessDate, string $key): void
{
    DB::transaction(fn () => app(RecordPlatformFeeReversal::class)->record(
        $entry->fresh(), 'correction', 'src', $key, User::factory()->create(), CarbonImmutable::parse($businessDate, 'Africa/Nairobi'),
    ));
}

function adjustEntry(PlatformFeeLedgerEntry $entry, PlatformFeeAdjustmentType $type, int $signed, string $businessDate, string $key): void
{
    DB::transaction(fn () => app(RecordPlatformFeeAdjustment::class)->record(
        $entry->fresh(), $type, $signed, 'correction', 'src', $key, User::factory()->create(), CarbonImmutable::parse($businessDate, 'Africa/Nairobi'),
    ));
}

// ---- 16.1 Basic future-cycle correction --------------------------------------------------------------

it('sweeps a pending reversal of an already-invoiced fee into a signed adjustment line on the next cycle', function (): void {
    [$merchant, $sub, $config, $inv] = corrScenario(500000);
    $earned = corrEarned($merchant, $config, $inv, 12500, '2026-07-05 10:00:00');

    // Cycle 1 (Jul): the earned fee is invoiced.
    $invoice1 = issueCycle($sub, '2026-07-01', '2026-08-01');
    expect($invoice1->subtotal_minor)->toBe(512500)->and($invoice1->total_minor)->toBe(512500);
    $earned->refresh();
    expect($earned->status)->toBe(PlatformFeeLedgerStatus::Invoiced)
        ->and($earned->subscription_invoice_item_id)->not->toBeNull();

    // A reversal is recorded after that invoice was issued → correction entry stays pending.
    reverseEntry($earned, '2026-08-05 09:00:00', 'rev:1');
    $correction = PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->where('reversed_entry_id', $earned->id)->firstOrFail();
    expect($correction->status)->toBe(PlatformFeeLedgerStatus::Pending);

    // Cycle 2 (Aug): the reversal is swept into a signed adjustment line; invoice1 is untouched.
    $invoice2 = issueCycle($sub, '2026-08-01', '2026-09-01');

    $adjust = $invoice2->items()->where('type', SubscriptionInvoiceItemType::Adjustment->value)->get();
    expect($adjust)->toHaveCount(1)
        ->and($adjust->first()->amount_minor)->toBe(-12500)
        ->and($invoice2->subtotal_minor)->toBe(487500) // plan 500000 + rollup 0 - correction 12500
        ->and($invoice2->total_minor)->toBe(487500)
        ->and($invoice2->balance_minor)->toBe(487500);

    $correction->refresh();
    expect($correction->status)->toBe(PlatformFeeLedgerStatus::Invoiced)
        ->and($correction->subscription_invoice_item_id)->toBe($adjust->first()->id);

    // The original earned fact and the first invoice are immutable.
    expect($invoice1->fresh()->total_minor)->toBe(512500)
        ->and($earned->fresh()->gross_platform_fee_minor)->toBe(12500);
});

it('does not sweep a correction whose original earned fee was never invoiced (no spurious credit)', function (): void {
    [$merchant, $sub, $config, $inv] = corrScenario(500000);
    // Earned fee is created but NEVER invoiced (no cycle 1 issuance before the reversal).
    $earned = corrEarned($merchant, $config, $inv, 12500, '2026-07-05 10:00:00');
    reverseEntry($earned, '2026-07-06 09:00:00', 'rev:noinv');

    // The original was dropped from the rollup by its reversed marker; the correction has no billing target.
    expect($earned->fresh()->status)->toBe(PlatformFeeLedgerStatus::Reversed);

    $invoice = issueCycle($sub, '2026-08-01', '2026-09-01');

    expect($invoice->items()->where('type', 'adjustment')->count())->toBe(0)
        ->and($invoice->total_minor)->toBe(500000); // plan only — no spurious -12500 credit
    $correction = PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->firstOrFail();
    expect($correction->status)->toBe(PlatformFeeLedgerStatus::Pending); // stays pending, never billed
});

// ---- 16.2 Mixed earned + correction cycle ------------------------------------------------------------

it('carries a positive rollup and a negative correction on the same cycle as two separate lines', function (): void {
    [$merchant, $sub, $config, $inv] = corrScenario(500000);
    $earned1 = corrEarned($merchant, $config, $inv, 10000, '2026-07-05 10:00:00');
    $invoice1 = issueCycle($sub, '2026-07-01', '2026-08-01'); // invoices earned1
    reverseEntry($earned1, '2026-08-02 09:00:00', 'rev:mix'); // pending -10000

    // A new earned fee lands in August → positive rollup, plus the carried reversal.
    $earned2 = corrEarned($merchant, $config, $inv, 6000, '2026-08-10 10:00:00');

    $invoice2 = issueCycle($sub, '2026-08-01', '2026-09-01');

    $rollup = $invoice2->items()->where('type', 'platform_fee_rollup')->first();
    $adjust = $invoice2->items()->where('type', 'adjustment')->first();
    expect($rollup->amount_minor)->toBe(6000)
        ->and($adjust->amount_minor)->toBe(-10000)
        ->and($invoice2->subtotal_minor)->toBe(496000) // 500000 + 6000 - 10000
        ->and($invoice2->total_minor)->toBe(496000);
});

// ---- 16.3 Correction-only cycle ----------------------------------------------------------------------

it('bills the plan fee plus the correction when a cycle has corrections but no new earned fees', function (): void {
    [$merchant, $sub, $config, $inv] = corrScenario(500000);
    $earned = corrEarned($merchant, $config, $inv, 12500, '2026-07-05 10:00:00');
    issueCycle($sub, '2026-07-01', '2026-08-01');
    adjustEntry($earned, PlatformFeeAdjustmentType::PartialRefund, -5000, '2026-08-03 09:00:00', 'adj:only');

    $invoice2 = issueCycle($sub, '2026-08-01', '2026-09-01');

    // The plan invoice always issues; the correction rides on it — no invoice is created solely for it.
    expect($invoice2->items()->where('type', 'platform_fee_rollup')->count())->toBe(0)
        ->and($invoice2->items()->where('type', 'adjustment')->first()->amount_minor)->toBe(-5000)
        ->and($invoice2->total_minor)->toBe(495000);
});

// ---- 16.4 Net-negative cycle -------------------------------------------------------------------------

it('caps the applied negative correction at the invoice total and carries the residual (never negative, never a wallet credit)', function (): void {
    [$merchant, $sub, $config, $inv] = corrScenario(10000); // small plan so corrections exceed it
    $e1 = corrEarned($merchant, $config, $inv, 8000, '2026-07-04 10:00:00');
    $e2 = corrEarned($merchant, $config, $inv, 8000, '2026-07-06 10:00:00');
    issueCycle($sub, '2026-07-01', '2026-08-01'); // invoices both (subtotal 10000 + 16000)

    reverseEntry($e1, '2026-08-02 09:00:00', 'rev:n1'); // -8000, earlier
    reverseEntry($e2, '2026-08-03 09:00:00', 'rev:n2'); // -8000, later

    // Cycle 2: headroom = plan 10000. Greedy consumes -8000 (fits), skips the second -8000 (would breach 0).
    $invoice2 = issueCycle($sub, '2026-08-01', '2026-09-01');

    expect($invoice2->items()->where('type', 'adjustment')->first()->amount_minor)->toBe(-8000)
        ->and($invoice2->subtotal_minor)->toBe(2000)
        ->and($invoice2->total_minor)->toBe(2000);
    // Invoice total never negative.
    expect($invoice2->total_minor)->toBeGreaterThanOrEqual(0);

    $c1 = PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->where('reversed_entry_id', $e1->id)->firstOrFail();
    $c2 = PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->where('reversed_entry_id', $e2->id)->firstOrFail();
    expect($c1->fresh()->status)->toBe(PlatformFeeLedgerStatus::Invoiced) // consumed
        ->and($c2->fresh()->status)->toBe(PlatformFeeLedgerStatus::Pending); // residual carried

    // No Wallet credit mechanism exists in Phase 20E.
    expect(Schema::hasTable('merchant_billing_credits'))->toBeFalse();

    // Cycle 3: the residual is now consumed once (never twice, never lost).
    $invoice3 = issueCycle($sub, '2026-09-01', '2026-10-01');
    expect($invoice3->items()->where('type', 'adjustment')->first()->amount_minor)->toBe(-8000)
        ->and($invoice3->total_minor)->toBe(2000)
        ->and($c2->fresh()->status)->toBe(PlatformFeeLedgerStatus::Invoiced);
});

// ---- 16.5 Idempotency and concurrency ---------------------------------------------------------------

it('is idempotent on re-issue: no duplicate adjustment line and no correction linked twice', function (): void {
    [$merchant, $sub, $config, $inv] = corrScenario(500000);
    $earned = corrEarned($merchant, $config, $inv, 12500, '2026-07-05 10:00:00');
    issueCycle($sub, '2026-07-01', '2026-08-01');
    reverseEntry($earned, '2026-08-05 09:00:00', 'rev:idem');

    $first = issueCycle($sub, '2026-08-01', '2026-09-01');
    $second = issueCycle($sub, '2026-08-01', '2026-09-01'); // same period → idempotent

    expect($second->id)->toBe($first->id)
        ->and(SubscriptionInvoiceItem::query()->where('subscription_invoice_id', $first->id)->where('type', 'adjustment')->count())->toBe(1)
        ->and(PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->where('status', 'invoiced')->count())->toBe(1);
});

it('a rolled-back issuance links no correction and consumes no correction status', function (): void {
    [$merchant, $sub, $config, $inv] = corrScenario(500000);
    $earned = corrEarned($merchant, $config, $inv, 12500, '2026-07-05 10:00:00');
    issueCycle($sub, '2026-07-01', '2026-08-01');
    reverseEntry($earned, '2026-08-05 09:00:00', 'rev:rb');
    $correction = PlatformFeeLedgerEntry::query()->where('entry_type', 'reversal')->firstOrFail();

    $sub->forceFill(['current_period_start' => '2026-08-01', 'current_period_end' => '2026-09-01'])->save();
    try {
        DB::transaction(function () use ($sub): void {
            app(IssueSubscriptionInvoice::class)->handle($sub->fresh(), User::factory()->create());
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($correction->fresh()->status)->toBe(PlatformFeeLedgerStatus::Pending)
        ->and($correction->fresh()->subscription_invoice_item_id)->toBeNull()
        ->and(SubscriptionInvoice::query()->where('period_start', '2026-08-01')->count())->toBe(0);
});

// ---- 16.6 Scope and currency ------------------------------------------------------------------------

it('excludes a future-dated correction and never sweeps another tenant correction', function (): void {
    [$merchantA, $subA, $config, $inv] = corrScenario(500000);
    $earnedA = corrEarned($merchantA, $config, $inv, 12500, '2026-07-05 10:00:00');
    issueCycle($subA, '2026-07-01', '2026-08-01');
    // Business date on/after the Aug cycle's exclusive end → excluded from the Aug sweep (stays pending).
    reverseEntry($earnedA, '2026-09-15 09:00:00', 'rev:future');

    // Merchant B — own subscription, sharing the platform-scoped settings + config; invoiced then reversed.
    $merchantB = Merchant::factory()->create();
    app(TenantContext::class)->bindForJob($merchantB);
    $planB = SubscriptionPlan::factory()->create();
    $priceB = SubscriptionPlanPrice::factory()->create(['plan_id' => $planB->id, 'billing_interval' => 'monthly', 'currency' => 'KES', 'amount_minor' => 300000]);
    $subB = MerchantSubscription::factory()->forMerchant($merchantB)->status(S::Active)->create([
        'plan_id' => $planB->id, 'price_id' => $priceB->id, 'billing_interval' => 'monthly',
        'current_period_start' => '2026-07-01', 'current_period_end' => '2026-08-01',
    ]);
    $earnedB = corrEarned($merchantB, $config, $inv, 4000, '2026-07-08 10:00:00');
    issueCycle($subB, '2026-07-01', '2026-08-01');
    reverseEntry($earnedB, '2026-08-04 09:00:00', 'rev:B');

    // Merchant A's Aug issuance: its own correction is future-dated (excluded); B's is out of tenant scope.
    app(TenantContext::class)->bindForJob($merchantA);
    $invoiceA = issueCycle($subA, '2026-08-01', '2026-09-01');

    expect($invoiceA->items()->where('type', 'adjustment')->count())->toBe(0)
        ->and($invoiceA->total_minor)->toBe(500000);

    $cA = PlatformFeeLedgerEntry::query()->where('reversed_entry_id', $earnedA->id)->firstOrFail();
    expect($cA->fresh()->status)->toBe(PlatformFeeLedgerStatus::Pending); // future-dated, carried

    // B's correction (queried within B's tenant scope) is never swept by A's issuance.
    app(TenantContext::class)->bindForJob($merchantB);
    $cB = PlatformFeeLedgerEntry::query()->where('reversed_entry_id', $earnedB->id)->firstOrFail();
    expect($cB->fresh()->status)->toBe(PlatformFeeLedgerStatus::Pending);
});
