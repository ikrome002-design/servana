<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\BillingEscalationEventType;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Enums\ScheduledPlanChangeStatus;
use App\Domain\Billing\Enums\SubscriptionInvoiceItemType;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Enums\WalletRegistrationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('billing', 'phase20b-enum-parity');

/*
 | Phase 20B canonical-enum parity (Plan §13.9, §13.15, §25.4). Proves the PHP enum backing
 | values are EXACTLY the values in each PostgreSQL CHECK constraint — zero mismatch. Parity to
 | API/OpenAPI/TS/screens is added with those layers (Increments 5–6).
 */

/** Extract the single-quoted allowed values from a PostgreSQL CHECK definition. */
function checkValues(string $table, string $constraint): array
{
    $rows = DB::select(
        'select pg_get_constraintdef(oid) as def from pg_constraint
         where conrelid = ?::regclass and conname = ?',
        [$table, $constraint],
    );

    expect($rows)->not->toBeEmpty("constraint {$constraint} on {$table} must exist");

    preg_match_all("/'([^']+)'/", $rows[0]->def, $matches);

    $values = array_values(array_unique($matches[1]));
    sort($values);

    return $values;
}

/** @param list<string> $enumValues */
function expectParity(string $table, string $constraint, array $enumValues): void
{
    sort($enumValues);
    expect(checkValues($table, $constraint))->toBe($enumValues);
}

it('merchant_subscriptions.status matches MerchantSubscriptionStatus', function (): void {
    expectParity('merchant_subscriptions', 'merchant_subscriptions_status_check', MerchantSubscriptionStatus::values());
});

it('merchant_subscriptions.billing_interval matches BillingInterval', function (): void {
    expectParity('merchant_subscriptions', 'merchant_subscriptions_billing_interval_check', BillingInterval::values());
});

it('merchants.billing_status matches MerchantBillingStatus (exactly five; no cancelled/expired)', function (): void {
    expectParity('merchants', 'merchants_billing_status_check', MerchantBillingStatus::values());

    expect(MerchantBillingStatus::values())->toHaveCount(5)
        ->not->toContain('cancelled')
        ->not->toContain('expired');
});

it('scheduled_plan_changes.status matches ScheduledPlanChangeStatus', function (): void {
    expectParity('scheduled_plan_changes', 'scheduled_plan_changes_status_check', ScheduledPlanChangeStatus::values());
});

it('subscription_invoices.status matches SubscriptionInvoiceStatus', function (): void {
    expectParity('subscription_invoices', 'subscription_invoices_status_check', SubscriptionInvoiceStatus::values());
});

it('subscription_invoices.wallet_registration_status matches WalletRegistrationStatus', function (): void {
    expectParity('subscription_invoices', 'subscription_invoices_wallet_registration_status_check', WalletRegistrationStatus::values());
});

it('subscription_invoice_items.type matches SubscriptionInvoiceItemType', function (): void {
    expectParity('subscription_invoice_items', 'subscription_invoice_items_type_check', SubscriptionInvoiceItemType::values());
});

it('billing_escalation_events.event_type matches BillingEscalationEventType', function (): void {
    expectParity('billing_escalation_events', 'billing_escalation_events_event_type_check', BillingEscalationEventType::values());
});

it('invoice_number_sequences.scope includes both merchant_client_invoice and subscription_invoice', function (): void {
    expect(checkValues('invoice_number_sequences', 'invoice_number_sequences_scope_check'))
        ->toBe(['merchant_client_invoice', 'subscription_invoice']);
});
