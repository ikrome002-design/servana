<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use App\Domain\Billing\Actions\MarkSubscriptionInvoiceOverdue;
use App\Domain\Billing\Actions\VoidSubscriptionInvoice;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Enums\SubscriptionInvoiceItemType;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Enums\WalletRegistrationStatus;
use App\Domain\Billing\Exceptions\BillingModeNotSupportedException;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('billing', 'phase20b-invoice', 'subscription-invoice');

function p20biMode(BillingMode $mode): void
{
    PlatformBillingSettings::factory()->create([
        'billing_mode' => $mode,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);
}

/** @return array{0:Merchant,1:MerchantSubscription} active subscription, tenant context bound, priced 500000. */
function p20biSub(int $amountMinor = 500000): array
{
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->bindForJob($merchant);
    $plan = SubscriptionPlan::factory()->create();
    $price = SubscriptionPlanPrice::factory()->create(['plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES', 'amount_minor' => $amountMinor]);
    $sub = MerchantSubscription::factory()->forMerchant($merchant)->status(S::Active)->create([
        'plan_id' => $plan->id,
        'price_id' => $price->id,
        'billing_interval' => 'monthly',
        'current_period_start' => '2026-07-01',
        'current_period_end' => '2026-08-01',
    ]);

    return [$merchant, $sub];
}

function p20biActor(): User
{
    return User::factory()->create();
}

it('issues a fixed-mode invoice equal to the captured price with an immutable plan_fee item', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [$merchant, $sub] = p20biSub(500000);

    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor());

    expect($invoice->status)->toBe(SubscriptionInvoiceStatus::Issued)
        ->and($invoice->subtotal_minor)->toBe(500000)
        ->and($invoice->discount_minor)->toBe(0)
        ->and($invoice->total_minor)->toBe(500000)
        ->and($invoice->balance_minor)->toBe(500000)
        ->and($invoice->currency)->toBe('KES')
        ->and($invoice->invoice_number)->toStartWith('SUB-')
        ->and($invoice->issued_at)->not->toBeNull()
        ->and($invoice->due_at)->not->toBeNull();

    $items = $invoice->items()->get();
    expect($items)->toHaveCount(1)
        ->and($items->first()->type)->toBe(SubscriptionInvoiceItemType::PlanFee)
        ->and($items->first()->amount_minor)->toBe(500000);
});

it('ships Wallet columns at their 20B defaults (null / unregistered) with no Wallet runtime', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [, $sub] = p20biSub();

    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor());

    expect($invoice->account_reference)->toBeNull()
        ->and($invoice->wallet_payment_id)->toBeNull()
        ->and($invoice->wallet_registration_status)->toBe(WalletRegistrationStatus::Unregistered)
        ->and($invoice->wallet_registered_at)->toBeNull();

    foreach (['subscription_payments', 'subscription_payment_attempts', 'wallet_webhook_inbox'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

it('is idempotent per subscription period', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [$merchant, $sub] = p20biSub();
    $action = app(IssueSubscriptionInvoice::class);

    $first = $action->handle($sub, p20biActor());
    $second = $action->handle($sub->fresh(), p20biActor());

    expect($second->id)->toBe($first->id)
        ->and(SubscriptionInvoice::query()->where('merchant_id', $merchant->id)->count())->toBe(1);
});

it('fails closed for percentage billing mode with no invoice, item, sequence, or audit', function (): void {
    p20biMode(BillingMode::PercentageOnMerchantClientInvoice);
    [$merchant, $sub] = p20biSub();
    $auditBefore = DB::table('audit_logs')->where('action', AuditEvent::SubscriptionInvoiceIssued->value)->count();

    expect(fn () => app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor()))
        ->toThrow(BillingModeNotSupportedException::class);

    expect(SubscriptionInvoice::query()->where('merchant_id', $merchant->id)->count())->toBe(0)
        ->and(SubscriptionInvoiceItem::query()->where('merchant_id', $merchant->id)->count())->toBe(0)
        ->and(DB::table('invoice_number_sequences')->where('merchant_id', $merchant->id)->where('scope', 'subscription_invoice')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', AuditEvent::SubscriptionInvoiceIssued->value)->count())->toBe($auditBefore);
});

it('makes issued financial fields immutable', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [, $sub] = p20biSub();
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor());

    $invoice->total_minor = 999999;
    expect(fn () => $invoice->save())->toThrow(DomainException::class);
});

it('makes invoice line items immutable and undeletable', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [, $sub] = p20biSub();
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor());
    $item = $invoice->items()->first();

    $item->amount_minor = 1;
    expect(fn () => $item->save())->toThrow(DomainException::class);
    expect(fn () => $invoice->items()->first()->delete())->toThrow(DomainException::class);
});

it('allocates independent per-merchant subscription-invoice numbers', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [$merchant, $sub] = p20biSub();
    $first = app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor());

    // A second period for the same merchant → next number.
    $sub->update(['current_period_start' => '2026-08-01', 'current_period_end' => '2026-09-01']);
    $second = app(IssueSubscriptionInvoice::class)->handle($sub->fresh(), p20biActor());

    expect($first->invoice_number)->toBe('SUB-000001')
        ->and($second->invoice_number)->toBe('SUB-000002')
        // merchant-client numbering is a separate scope/counter.
        ->and(DB::table('invoice_number_sequences')->where('merchant_id', $merchant->id)->where('scope', 'subscription_invoice')->value('next_value'))->toBe(3);
});

it('transitions issued -> overdue and is idempotent', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [, $sub] = p20biSub();
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor());

    app(MarkSubscriptionInvoiceOverdue::class)->handle($invoice, p20biActor());
    app(MarkSubscriptionInvoiceOverdue::class)->handle($invoice->fresh(), p20biActor()); // idempotent

    expect($invoice->fresh()->status)->toBe(SubscriptionInvoiceStatus::Overdue);
});

it('voids an issued invoice (void terminology, never cancelled)', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [, $sub] = p20biSub();
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor());

    $voided = app(VoidSubscriptionInvoice::class)->handle($invoice, p20biActor());

    expect($voided->status)->toBe(SubscriptionInvoiceStatus::Void);
});

it('rejects an invalid invoice transition (422 invalid_state_transition)', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [, $sub] = p20biSub();
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor());
    app(VoidSubscriptionInvoice::class)->handle($invoice, p20biActor());

    // void is terminal → cannot mark overdue.
    expect(fn () => app(MarkSubscriptionInvoiceOverdue::class)->handle($invoice->fresh(), p20biActor()))
        ->toThrow(BillingStateException::class);
});

it('creates no percentage-ledger row for a fixed-mode invoice', function (): void {
    p20biMode(BillingMode::FixedAmount);
    [, $sub] = p20biSub();
    app(IssueSubscriptionInvoice::class)->handle($sub, p20biActor());

    // platform_fee_ledger_entries is Phase 20E — it must not even exist yet.
    expect(Schema::hasTable('platform_fee_ledger_entries'))->toBeFalse();
});
