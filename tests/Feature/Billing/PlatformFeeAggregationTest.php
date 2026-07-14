<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Enums\SubscriptionInvoiceItemType;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Services\PlatformFeeLedgerEntryStateMachine;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-aggregation');

/*
 | Phase 20E Increment 5A — aggregation of earned platform-fee liabilities into the period's
 | subscription invoice (Plan §51, §49). PostgreSQL 16. The rollup is folded into IssueSubscriptionInvoice
 | at issuance (subscription_invoices requires plan/price and is immutable once issued).
 */

/** @return array{0:Merchant,1:MerchantSubscription,2:PlatformFeeConfiguration,3:Invoice} active monthly subscription + a percentage config + a clean source invoice. */
function aggScenario(int $planPrice = 500000, string $periodStart = '2026-07-01', string $periodEnd = '2026-08-01'): array
{
    PlatformBillingSettings::factory()->create([
        'billing_mode' => BillingMode::FixedAmount,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);

    $merchant = Merchant::factory()->create();
    // Build the FK-target source invoice BEFORE binding the tenant so the nested factory chain
    // (branch/client) creates its own coherent tenant without the bound-tenant merchant_id override.
    $sourceInvoice = Invoice::factory()->issued()->create();
    app(TenantContext::class)->bindForJob($merchant);
    $plan = SubscriptionPlan::factory()->create();
    $price = SubscriptionPlanPrice::factory()->create(['plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES', 'amount_minor' => $planPrice]);
    $sub = MerchantSubscription::factory()->forMerchant($merchant)->status(S::Active)->create([
        'plan_id' => $plan->id,
        'price_id' => $price->id,
        'billing_interval' => 'monthly',
        'current_period_start' => $periodStart,
        'current_period_end' => $periodEnd,
    ]);
    $config = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subYears(2), 'effective_to' => null,
    ]);

    return [$merchant, $sub, $config, $sourceInvoice];
}

/** An earned/pending platform-fee ledger entry for $merchant billable at $billableAt (Nairobi instant). */
function earnedEntry(Merchant $merchant, PlatformFeeConfiguration $config, Invoice $sourceInvoice, int $gross, string $billableAt, string $currency = 'KES'): PlatformFeeLedgerEntry
{
    return PlatformFeeLedgerEntry::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => null,
        'source_invoice_id' => $sourceInvoice->id,
        'source_invoice_item_id' => null,
        'entry_type' => 'earned',
        'status' => 'pending',
        'effective_configuration_id' => $config->id,
        'service_fee_tier_snapshot' => 'customer_centric',
        'fee_basis_type' => 'merchant_client_invoice_service_subtotal',
        'percentage_rate_snapshot' => 250,
        'shared_split_snapshot' => null,
        'gross_platform_fee_minor' => $gross,
        'client_shifted_amount_minor' => 0,
        'merchant_absorbed_amount_minor' => $gross,
        'merchant_liability_minor' => $gross,
        'currency' => $currency,
        'subscription_invoice_item_id' => null,
        // Store the true UTC instant of the intended Nairobi wall-clock (matches how production stamps
        // billable_at = now() in UTC; the query converts back to the Nairobi calendar date).
        'billable_at' => CarbonImmutable::parse($billableAt, 'Africa/Nairobi')->utc(),
    ]);
}

function aggIssue(MerchantSubscription $sub): SubscriptionInvoice
{
    return app(IssueSubscriptionInvoice::class)->handle($sub->fresh(), User::factory()->create());
}

it('aggregates only pending earned entries inside the period into one rollup line', function (): void {
    [$merchant, $sub, $config, $inv] = aggScenario();
    $a = earnedEntry($merchant, $config, $inv, 6250, '2026-07-05 10:00:00');
    $b = earnedEntry($merchant, $config, $inv, 3750, '2026-07-20 10:00:00');

    $invoice = aggIssue($sub);

    $rollups = $invoice->items()->where('type', SubscriptionInvoiceItemType::PlatformFeeRollup->value)->get();
    expect($rollups)->toHaveCount(1)
        ->and($rollups->first()->amount_minor)->toBe(10000)
        ->and($invoice->subtotal_minor)->toBe(510000) // plan 500000 + rollup 10000
        ->and($invoice->total_minor)->toBe(510000);

    foreach ([$a, $b] as $entry) {
        $entry->refresh();
        expect($entry->status)->toBe(PlatformFeeLedgerStatus::Invoiced)
            ->and($entry->subscription_invoice_item_id)->toBe($rollups->first()->id);
    }
});

it('excludes entries outside the Africa/Nairobi period boundary (inclusive start, exclusive end)', function (): void {
    [$merchant, $sub, $config, $inv] = aggScenario(500000, '2026-07-01', '2026-08-01');
    // Just before period start (Nairobi) and exactly at the exclusive end → excluded.
    $before = earnedEntry($merchant, $config, $inv, 1000, '2026-06-30 23:59:59');
    $atStart = earnedEntry($merchant, $config, $inv, 2000, '2026-07-01 00:00:00'); // inclusive
    $atEnd = earnedEntry($merchant, $config, $inv, 4000, '2026-08-01 00:00:00');   // exclusive → next period

    $invoice = aggIssue($sub);

    $rollup = $invoice->items()->where('type', 'platform_fee_rollup')->first();
    expect($rollup->amount_minor)->toBe(2000); // only the at-start entry
    expect($before->fresh()->status)->toBe(PlatformFeeLedgerStatus::Pending)
        ->and($atEnd->fresh()->status)->toBe(PlatformFeeLedgerStatus::Pending)
        ->and($atStart->fresh()->status)->toBe(PlatformFeeLedgerStatus::Invoiced);
});

it('never mixes another merchant or another currency into the rollup', function (): void {
    [$merchant, $sub, $config, $inv] = aggScenario();
    earnedEntry($merchant, $config, $inv, 5000, '2026-07-10 09:00:00');
    earnedEntry($merchant, $config, $inv, 1234, '2026-07-11 09:00:00', 'USD'); // wrong currency → excluded

    // A different merchant's entry must never appear on this invoice. (Reuse the same platform-scoped
    // config — a second active KES percentage config would violate the effective-window exclusion.)
    $other = Merchant::factory()->create();
    earnedEntry($other, $config, $inv, 9999, '2026-07-12 09:00:00');

    $invoice = aggIssue($sub);

    $rollup = $invoice->items()->where('type', 'platform_fee_rollup')->first();
    expect($rollup->amount_minor)->toBe(5000);
});

it('is stable under billable_at + ulid ordering and idempotent on re-issue', function (): void {
    [$merchant, $sub, $config, $inv] = aggScenario();
    earnedEntry($merchant, $config, $inv, 100, '2026-07-05 08:00:00');
    earnedEntry($merchant, $config, $inv, 200, '2026-07-05 08:00:00'); // tie on billable_at → ulid order
    earnedEntry($merchant, $config, $inv, 300, '2026-07-06 08:00:00');

    $first = aggIssue($sub);
    $second = aggIssue($sub); // idempotent — same period returns the same invoice

    expect($second->id)->toBe($first->id)
        ->and(SubscriptionInvoice::query()->where('merchant_id', $merchant->id)->count())->toBe(1)
        ->and(SubscriptionInvoiceItem::query()->where('subscription_invoice_id', $first->id)->where('type', 'platform_fee_rollup')->count())->toBe(1)
        ->and($first->items()->where('type', 'platform_fee_rollup')->first()->amount_minor)->toBe(600);
});

it('links each source entry exactly once and consumes exactly one invoice number', function (): void {
    [$merchant, $sub, $config, $inv] = aggScenario();
    $e = earnedEntry($merchant, $config, $inv, 7777, '2026-07-15 12:00:00');

    $invoice = aggIssue($sub);

    expect(PlatformFeeLedgerEntry::query()->where('subscription_invoice_item_id', '!=', null)->count())->toBe(1)
        ->and($e->fresh()->subscription_invoice_item_id)->not->toBeNull()
        ->and($invoice->invoice_number)->toBe('SUB-000001')
        ->and(DB::table('invoice_number_sequences')->where('merchant_id', $merchant->id)->where('scope', 'subscription_invoice')->value('next_value'))->toBe(2);
});

it('the cycle-level DB guard forbids a second platform_fee_rollup line on one invoice', function (): void {
    [$merchant, $sub, $config, $inv] = aggScenario();
    earnedEntry($merchant, $config, $inv, 5000, '2026-07-10 09:00:00'); // issuance writes the first rollup
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, User::factory()->create());

    // Directly attempting a second rollup line on the same invoice violates the partial-unique index.
    expect(fn () => SubscriptionInvoiceItem::query()->create([
        'merchant_id' => $merchant->id,
        'subscription_invoice_id' => $invoice->id,
        'description' => 'Duplicate rollup',
        'amount_minor' => 1,
        'type' => SubscriptionInvoiceItemType::PlatformFeeRollup->value,
    ]))->toThrow(QueryException::class);
})->group('phase20e-aggregation-guard');

it('a fixed-only / activity-free cycle contributes no rollup line (Phase 20B invoice unchanged)', function (): void {
    [$merchant, $sub, $config, $inv] = aggScenario();
    // No earned entries at all.
    $invoice = aggIssue($sub);

    expect($invoice->items()->where('type', 'platform_fee_rollup')->count())->toBe(0)
        ->and($invoice->subtotal_minor)->toBe(500000)
        ->and($invoice->total_minor)->toBe(500000);
});

it('a failed issuance consumes no invoice number and marks no entry (rollback safe)', function (): void {
    [$merchant, $sub, $config, $inv] = aggScenario();
    $e = earnedEntry($merchant, $config, $inv, 5000, '2026-07-10 09:00:00');

    // Force a failure AFTER number allocation by making the audit recorder throw inside the txn.
    app()->bind(AuditRecorder::class, function () {
        return new class implements AuditRecorder
        {
            public function record(
                AuditEvent $event,
                ?User $actor = null,
                ?int $merchantId = null,
                ?int $branchId = null,
                ?object $subject = null,
                array $context = [],
            ): AuditLog {
                throw new RuntimeException('boom');
            }
        };
    });

    expect(fn () => app(IssueSubscriptionInvoice::class)->handle($sub, User::factory()->create()))
        ->toThrow(RuntimeException::class);

    expect(SubscriptionInvoice::query()->where('merchant_id', $merchant->id)->count())->toBe(0)
        ->and($e->fresh()->status)->toBe(PlatformFeeLedgerStatus::Pending)
        ->and($e->fresh()->subscription_invoice_item_id)->toBeNull()
        ->and(DB::table('invoice_number_sequences')->where('merchant_id', $merchant->id)->where('scope', 'subscription_invoice')->count())->toBe(0);
});

it('the ledger state machine forbids an unlisted transition (422 invalid_state_transition)', function (): void {
    $machine = app(PlatformFeeLedgerEntryStateMachine::class);

    // Valid rollup path.
    expect($machine->canTransition(PlatformFeeLedgerStatus::Pending, PlatformFeeLedgerStatus::Aggregated))->toBeTrue()
        ->and($machine->canTransition(PlatformFeeLedgerStatus::Aggregated, PlatformFeeLedgerStatus::Invoiced))->toBeTrue();

    // invoiced is terminal-for-billing → cannot go back to aggregated.
    expect(fn () => $machine->ensure(PlatformFeeLedgerStatus::Invoiced, PlatformFeeLedgerStatus::Aggregated))
        ->toThrow(BillingStateException::class);
});

it('no Wallet/provider/outbox table is touched by aggregation', function (): void {
    [$merchant, $sub, $config, $inv] = aggScenario();
    earnedEntry($merchant, $config, $inv, 5000, '2026-07-10 09:00:00');
    aggIssue($sub);

    foreach (['subscription_payments', 'subscription_payment_attempts', 'wallet_webhook_inbox'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});
