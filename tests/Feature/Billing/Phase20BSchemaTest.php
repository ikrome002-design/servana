<?php

declare(strict_types=1);

use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantOwnership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('billing', 'phase20b-schema');

/*
 | Phase 20B DB invariants (Plan §13.9, §13.15, §22, §48, §49, §54; ADR-014). Five merchant-
 | owned tables + the merchants billing-status columns + the invoice-number scope expand. One
 | deliberately failing write per isolated test (PostgreSQL aborts the transaction on a
 | constraint violation). Parents (merchants/plans/prices/users) are built with factories; the
 | 20B rows use raw DB::table inserts so invalid values can be exercised directly.
 */

function p20bMerchantId(): int
{
    return Merchant::factory()->create()->id;
}

/** @return array{0:int,1:int} [planId, priceId] with the price belonging to the plan. */
function p20bPlanPrice(array $priceOverrides = []): array
{
    $plan = SubscriptionPlan::factory()->create();
    $price = SubscriptionPlanPrice::factory()->create(array_merge([
        'plan_id' => $plan->id,
        'billing_interval' => 'monthly',
        'amount_minor' => 500000,
        'currency' => 'KES',
    ], $priceOverrides));

    return [$plan->id, $price->id];
}

/** @param array<string,mixed> $overrides */
function p20bSubscriptionRow(int $merchantId, int $planId, int $priceId, array $overrides = []): array
{
    return array_merge([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $merchantId,
        'plan_id' => $planId,
        'price_id' => $priceId,
        'status' => 'trialing',
        'billing_interval' => 'monthly',
        'trial_days_snapshot' => 14,
        'trial_started_at' => now(),
        'trial_ends_at' => now()->addDays(14),
        'current_period_start' => today()->toDateString(),
        'current_period_end' => today()->addMonth()->toDateString(),
        'high_value_payout_threshold_minor' => null,
        'cancelled_at' => null,
        'expired_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function p20bInsertSubscription(int $merchantId, int $planId, int $priceId, array $overrides = []): int
{
    return (int) DB::table('merchant_subscriptions')->insertGetId(
        p20bSubscriptionRow($merchantId, $planId, $priceId, $overrides)
    );
}

/** @param array<string,mixed> $overrides */
function p20bInvoiceRow(int $merchantId, int $planId, int $priceId, array $overrides = []): array
{
    return array_merge([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $merchantId,
        'plan_id' => $planId,
        'price_id' => $priceId,
        'invoice_number' => null,
        'period_start' => today()->toDateString(),
        'period_end' => today()->addMonth()->toDateString(),
        'subtotal_minor' => 500000,
        'discount_minor' => 0,
        'total_minor' => 500000,
        'currency' => 'KES',
        'balance_minor' => 500000,
        'status' => 'draft',
        'account_reference' => null,
        'wallet_payment_id' => null,
        'wallet_registration_status' => 'unregistered',
        'wallet_registered_at' => null,
        'issued_at' => null,
        'due_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

// ─── Existence + ownership ──────────────────────────────────────────────────

it('creates all five Phase 20B tables', function (): void {
    foreach ([
        'merchant_subscriptions', 'scheduled_plan_changes', 'subscription_invoices',
        'subscription_invoice_items', 'billing_escalation_events',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} must exist");
    }
});

it('classifies every Phase 20B table as merchant-owned (tenant), never platform-exempt', function (): void {
    foreach ([
        'merchant_subscriptions', 'scheduled_plan_changes', 'subscription_invoices',
        'subscription_invoice_items', 'billing_escalation_events',
    ] as $table) {
        expect(in_array($table, TenantOwnership::TENANT_OWNED, true))->toBeTrue("{$table} must be TENANT_OWNED")
            ->and(array_key_exists($table, TenantOwnership::EXEMPT))->toBeFalse("{$table} must NOT be EXEMPT");
    }
});

it('requires a non-null merchant_id with an index on every Phase 20B table', function (): void {
    foreach ([
        'merchant_subscriptions', 'scheduled_plan_changes', 'subscription_invoices',
        'subscription_invoice_items', 'billing_escalation_events',
    ] as $table) {
        $col = collect(DB::select(
            'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
            [$table, 'merchant_id'],
        ))->first();
        expect($col)->not->toBeNull("{$table}.merchant_id must exist")
            ->and($col->is_nullable)->toBe('NO', "{$table}.merchant_id must be NOT NULL");

        $hasIndex = collect(DB::select('select indexdef from pg_indexes where tablename = ?', [$table]))
            ->contains(fn ($i): bool => str_contains($i->indexdef, '(merchant_id'));
        expect($hasIndex)->toBeTrue("{$table} must have an index beginning with merchant_id");
    }
});

it('carries no branch_id on any Phase 20B table (merchant-level, not branch-level)', function (): void {
    foreach ([
        'merchant_subscriptions', 'scheduled_plan_changes', 'subscription_invoices',
        'subscription_invoice_items', 'billing_escalation_events',
    ] as $table) {
        expect(Schema::hasColumn($table, 'branch_id'))->toBeFalse("{$table} must not carry branch_id");
    }
});

// ─── merchant_subscriptions ─────────────────────────────────────────────────

it('accepts a valid trialing subscription', function (): void {
    [$plan, $price] = p20bPlanPrice();
    $id = p20bInsertSubscription(p20bMerchantId(), $plan, $price);
    expect($id)->toBeGreaterThan(0);
});

it('rejects an invalid subscription status', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => p20bInsertSubscription(p20bMerchantId(), $plan, $price, ['status' => 'bogus']))
        ->toThrow(QueryException::class);
});

it('rejects an invalid billing interval', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => p20bInsertSubscription(p20bMerchantId(), $plan, $price, ['billing_interval' => 'fortnightly']))
        ->toThrow(QueryException::class);
});

it('rejects a negative trial_days_snapshot', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => p20bInsertSubscription(p20bMerchantId(), $plan, $price, ['trial_days_snapshot' => -1]))
        ->toThrow(QueryException::class);
});

it('rejects period_end not after period_start', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => p20bInsertSubscription(p20bMerchantId(), $plan, $price, [
        'current_period_start' => today()->toDateString(),
        'current_period_end' => today()->toDateString(),
    ]))->toThrow(QueryException::class);
});

it('rejects trial_ends_at before trial_started_at', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => p20bInsertSubscription(p20bMerchantId(), $plan, $price, [
        'trial_started_at' => now(),
        'trial_ends_at' => now()->subDay(),
    ]))->toThrow(QueryException::class);
});

it('rejects a negative high-value payout threshold', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => p20bInsertSubscription(p20bMerchantId(), $plan, $price, ['high_value_payout_threshold_minor' => -5]))
        ->toThrow(QueryException::class);
});

it('rejects a price that does not belong to the plan (composite FK)', function (): void {
    [$planA, $priceA] = p20bPlanPrice();
    $planB = SubscriptionPlan::factory()->create()->id;
    expect(fn () => p20bInsertSubscription(p20bMerchantId(), $planB, $priceA))
        ->toThrow(QueryException::class);
});

it('allows only one current non-terminal subscription per merchant', function (): void {
    $merchant = p20bMerchantId();
    [$plan, $price] = p20bPlanPrice();
    p20bInsertSubscription($merchant, $plan, $price, ['status' => 'active']);

    [$plan2, $price2] = p20bPlanPrice();
    expect(fn () => p20bInsertSubscription($merchant, $plan2, $price2, ['status' => 'trialing']))
        ->toThrow(QueryException::class);
});

it('retains terminal history alongside a new current subscription', function (): void {
    $merchant = p20bMerchantId();
    [$plan, $price] = p20bPlanPrice();
    p20bInsertSubscription($merchant, $plan, $price, ['status' => 'cancelled', 'cancelled_at' => now()]);
    [$plan2, $price2] = p20bPlanPrice();
    p20bInsertSubscription($merchant, $plan2, $price2, ['status' => 'expired', 'expired_at' => now()]);
    [$plan3, $price3] = p20bPlanPrice();
    $current = p20bInsertSubscription($merchant, $plan3, $price3, ['status' => 'active']);

    expect($current)->toBeGreaterThan(0)
        ->and(DB::table('merchant_subscriptions')->where('merchant_id', $merchant)->count())->toBe(3);
});

// ─── scheduled_plan_changes ─────────────────────────────────────────────────

it('enforces one scheduled change per subscription and effective boundary', function (): void {
    $merchant = p20bMerchantId();
    [$plan, $price] = p20bPlanPrice();
    $sub = p20bInsertSubscription($merchant, $plan, $price, ['status' => 'active']);
    [$tPlan, $tPrice] = p20bPlanPrice();
    $effective = today()->addMonth()->toDateString();

    $row = fn () => DB::table('scheduled_plan_changes')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $merchant,
        'merchant_subscription_id' => $sub,
        'target_plan_id' => $tPlan,
        'target_price_id' => $tPrice,
        'effective_at' => $effective,
        'status' => 'scheduled',
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row();
    expect($row)->toThrow(QueryException::class);
});

it('rejects a scheduled target price that does not belong to the target plan', function (): void {
    $merchant = p20bMerchantId();
    [$plan, $price] = p20bPlanPrice();
    $sub = p20bInsertSubscription($merchant, $plan, $price, ['status' => 'active']);
    [$tPlanA, $tPriceA] = p20bPlanPrice();
    $tPlanB = SubscriptionPlan::factory()->create()->id;

    expect(fn () => DB::table('scheduled_plan_changes')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $merchant,
        'merchant_subscription_id' => $sub,
        'target_plan_id' => $tPlanB,
        'target_price_id' => $tPriceA,
        'effective_at' => today()->addMonth()->toDateString(),
        'status' => 'scheduled',
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ─── subscription_invoices ──────────────────────────────────────────────────

it('accepts a valid draft invoice with 20B Wallet defaults', function (): void {
    [$plan, $price] = p20bPlanPrice();
    $id = DB::table('subscription_invoices')->insertGetId(p20bInvoiceRow(p20bMerchantId(), $plan, $price));
    $row = DB::table('subscription_invoices')->find($id);

    expect($row->wallet_registration_status)->toBe('unregistered')
        ->and($row->account_reference)->toBeNull()
        ->and($row->wallet_payment_id)->toBeNull()
        ->and($row->wallet_registered_at)->toBeNull();
});

it('rejects discount greater than subtotal', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => DB::table('subscription_invoices')->insert(
        p20bInvoiceRow(p20bMerchantId(), $plan, $price, ['discount_minor' => 600000, 'total_minor' => -100000, 'balance_minor' => 0])
    ))->toThrow(QueryException::class);
});

it('rejects total that is not subtotal minus discount', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => DB::table('subscription_invoices')->insert(
        p20bInvoiceRow(p20bMerchantId(), $plan, $price, ['discount_minor' => 100000, 'total_minor' => 500000, 'balance_minor' => 400000])
    ))->toThrow(QueryException::class);
});

it('rejects balance greater than total', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => DB::table('subscription_invoices')->insert(
        p20bInvoiceRow(p20bMerchantId(), $plan, $price, ['balance_minor' => 600000])
    ))->toThrow(QueryException::class);
});

it('rejects lowercase currency', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => DB::table('subscription_invoices')->insert(
        p20bInvoiceRow(p20bMerchantId(), $plan, $price, ['currency' => 'kes'])
    ))->toThrow(QueryException::class);
});

it('rejects period_end not after period_start on an invoice', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => DB::table('subscription_invoices')->insert(
        p20bInvoiceRow(p20bMerchantId(), $plan, $price, ['period_end' => today()->toDateString()])
    ))->toThrow(QueryException::class);
});

it('rejects an unregistered invoice that carries a Wallet account_reference (coherence)', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => DB::table('subscription_invoices')->insert(
        p20bInvoiceRow(p20bMerchantId(), $plan, $price, ['account_reference' => 'SRV-PAY-FAKE'])
    ))->toThrow(QueryException::class);
});

it('rejects a registered invoice missing its Wallet fields (coherence)', function (): void {
    [$plan, $price] = p20bPlanPrice();
    expect(fn () => DB::table('subscription_invoices')->insert(
        p20bInvoiceRow(p20bMerchantId(), $plan, $price, ['wallet_registration_status' => 'registered'])
    ))->toThrow(QueryException::class);
});

it('enforces per-merchant subscription-invoice-number uniqueness', function (): void {
    $merchant = p20bMerchantId();
    [$plan, $price] = p20bPlanPrice();
    DB::table('subscription_invoices')->insert(p20bInvoiceRow($merchant, $plan, $price, ['invoice_number' => 'SUB-1', 'status' => 'issued', 'issued_at' => now()]));
    [$plan2, $price2] = p20bPlanPrice();
    expect(fn () => DB::table('subscription_invoices')->insert(
        p20bInvoiceRow($merchant, $plan2, $price2, ['invoice_number' => 'SUB-1', 'status' => 'issued', 'issued_at' => now()])
    ))->toThrow(QueryException::class);
});

// ─── subscription_invoice_items ─────────────────────────────────────────────

it('accepts a plan_fee item and rejects an invalid item type', function (): void {
    [$plan, $price] = p20bPlanPrice();
    $merchant = p20bMerchantId();
    $invoice = DB::table('subscription_invoices')->insertGetId(p20bInvoiceRow($merchant, $plan, $price));

    DB::table('subscription_invoice_items')->insert([
        'ulid' => (string) Str::ulid(), 'merchant_id' => $merchant, 'subscription_invoice_id' => $invoice,
        'description' => 'Plan fee', 'amount_minor' => 500000, 'type' => 'plan_fee',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('subscription_invoice_items')->insert([
        'ulid' => (string) Str::ulid(), 'merchant_id' => $merchant, 'subscription_invoice_id' => $invoice,
        'description' => 'Bad', 'amount_minor' => 1, 'type' => 'mystery',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a negative non-adjustment item (sign rule)', function (): void {
    [$plan, $price] = p20bPlanPrice();
    $merchant = p20bMerchantId();
    $invoice = DB::table('subscription_invoices')->insertGetId(p20bInvoiceRow($merchant, $plan, $price));

    expect(fn () => DB::table('subscription_invoice_items')->insert([
        'ulid' => (string) Str::ulid(), 'merchant_id' => $merchant, 'subscription_invoice_id' => $invoice,
        'description' => 'Negative plan fee', 'amount_minor' => -1, 'type' => 'plan_fee',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects an item whose merchant differs from its invoice (composite FK)', function (): void {
    [$plan, $price] = p20bPlanPrice();
    $merchant = p20bMerchantId();
    $invoice = DB::table('subscription_invoices')->insertGetId(p20bInvoiceRow($merchant, $plan, $price));

    expect(fn () => DB::table('subscription_invoice_items')->insert([
        'ulid' => (string) Str::ulid(), 'merchant_id' => p20bMerchantId(), 'subscription_invoice_id' => $invoice,
        'description' => 'Cross-tenant', 'amount_minor' => 1, 'type' => 'plan_fee',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ─── billing_escalation_events ──────────────────────────────────────────────

it('enforces escalation idempotency per (subscription, event_type, period_boundary)', function (): void {
    $merchant = p20bMerchantId();
    [$plan, $price] = p20bPlanPrice();
    $sub = p20bInsertSubscription($merchant, $plan, $price, ['status' => 'active']);
    $boundary = today()->addMonth()->toDateString();

    $row = fn () => DB::table('billing_escalation_events')->insert([
        'ulid' => (string) Str::ulid(), 'merchant_id' => $merchant, 'subscription_invoice_id' => null,
        'merchant_subscription_id' => $sub, 'event_type' => 'overdue', 'from_billing_status' => 'active',
        'to_billing_status' => 'overdue', 'reason' => null, 'period_boundary' => $boundary, 'created_at' => now(),
    ]);

    $row();
    expect($row)->toThrow(QueryException::class);
});

it('allows the same event_type for a different period boundary', function (): void {
    $merchant = p20bMerchantId();
    [$plan, $price] = p20bPlanPrice();
    $sub = p20bInsertSubscription($merchant, $plan, $price, ['status' => 'active']);

    foreach ([today()->addMonth(), today()->addMonths(2)] as $boundary) {
        DB::table('billing_escalation_events')->insert([
            'ulid' => (string) Str::ulid(), 'merchant_id' => $merchant, 'subscription_invoice_id' => null,
            'merchant_subscription_id' => $sub, 'event_type' => 'overdue', 'from_billing_status' => 'active',
            'to_billing_status' => 'overdue', 'reason' => null, 'period_boundary' => $boundary->toDateString(), 'created_at' => now(),
        ]);
    }

    expect(DB::table('billing_escalation_events')->where('merchant_subscription_id', $sub)->count())->toBe(2);
});

it('has no updated_at column on the append-only escalation log', function (): void {
    expect(Schema::hasColumn('billing_escalation_events', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('billing_escalation_events', 'created_at'))->toBeTrue();
});

// ─── merchants billing-status + reason ──────────────────────────────────────

it('adds merchants.billing_status defaulting to trialing with a nullable reason', function (): void {
    $merchant = Merchant::factory()->create();
    $row = DB::table('merchants')->where('id', $merchant->id)->first();

    expect($row->billing_status)->toBe('trialing')
        ->and($row->billing_status_reason)->toBeNull()
        ->and(columnNullableP20b('merchants', 'billing_status'))->toBeFalse();
});

it('supports the Gate B2 terminal reason codes in billing_status_reason', function (): void {
    $merchant = Merchant::factory()->create();
    DB::table('merchants')->where('id', $merchant->id)->update([
        'billing_status' => 'suspended_billing',
        'billing_status_reason' => 'subscription_cancelled',
    ]);
    DB::table('merchants')->where('id', $merchant->id)->update(['billing_status_reason' => 'subscription_expired']);

    expect(DB::table('merchants')->where('id', $merchant->id)->value('billing_status_reason'))
        ->toBe('subscription_expired');
});

it('rejects an invalid merchants.billing_status', function (): void {
    $merchant = Merchant::factory()->create();
    expect(fn () => DB::table('merchants')->where('id', $merchant->id)->update(['billing_status' => 'bogus']))
        ->toThrow(QueryException::class);
});

// ─── invoice-number scope independence + no Wallet runtime ──────────────────

it('keeps merchant-client and subscription-invoice counters independent', function (): void {
    $merchant = p20bMerchantId();
    DB::table('invoice_number_sequences')->insert([
        'merchant_id' => $merchant, 'scope' => 'merchant_client_invoice', 'next_value' => 5, 'prefix' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invoice_number_sequences')->insert([
        'merchant_id' => $merchant, 'scope' => 'subscription_invoice', 'next_value' => 1, 'prefix' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('invoice_number_sequences')->where('merchant_id', $merchant)->count())->toBe(2);

    // The unique(merchant_id, scope) prevents a duplicate counter per scope.
    expect(fn () => DB::table('invoice_number_sequences')->insert([
        'merchant_id' => $merchant, 'scope' => 'subscription_invoice', 'next_value' => 9, 'prefix' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects an invalid invoice_number_sequences scope', function (): void {
    expect(fn () => DB::table('invoice_number_sequences')->insert([
        'merchant_id' => p20bMerchantId(), 'scope' => 'wallet_invoice', 'next_value' => 1, 'prefix' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('introduces no Wallet runtime tables in Phase 20B', function (): void {
    foreach ([
        'wallet_merchant_account_links', 'subscription_payment_attempts', 'subscription_payments',
        'subscription_payment_receipts', 'wallet_webhook_inbox', 'billing_reconciliation_exceptions',
        'subscription_invoice_payment_locks', 'merchant_billing_credits',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} must NOT exist in Phase 20B (owner: 20D-W)");
    }
});

function columnNullableP20b(string $table, string $column): bool
{
    $row = collect(DB::select(
        'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        [$table, $column],
    ))->first();

    return $row !== null && $row->is_nullable === 'YES';
}
