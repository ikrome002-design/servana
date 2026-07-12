<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * subscription_invoices — immutable issued invoice financial snapshot (Plan §13.9, §25.4,
 * §49; ADR-014; Phase 20B). Merchant-owned. Integer minor units only. `invoice_number` is
 * allocated gap-free per merchant from `invoice_number_sequences` scope `subscription_invoice`
 * at issuance (null while draft; Gate B3). Cancellation terminology is `void` only.
 *
 * Wallet columns (`account_reference`, `wallet_payment_id`, `wallet_registration_status`,
 * `wallet_registered_at`) are an ORTHOGONAL technical projection (ADR-014), ship at their
 * defaults (null / 'unregistered') in Phase 20B, and are populated only by Phase 20D-W — there
 * is NO Wallet client, outbox, or registration call in 20B. Issuance fails closed for any
 * non-fixed billing mode (Gate B5). See docs/architecture/state-machines/subscription-invoice.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->foreignId('price_id')->constrained('subscription_plan_prices')->restrictOnDelete();
            $table->string('invoice_number')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->bigInteger('balance_minor');
            $table->string('status', 24)->default('draft');
            $table->string('account_reference')->nullable();
            $table->string('wallet_payment_id')->nullable()->unique();
            $table->string('wallet_registration_status', 16)->default('unregistered');
            $table->timestampTz('wallet_registered_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'status']);
            $table->unique(['id', 'merchant_id'], 'subscription_invoices_id_merchant_id_unique');
        });

        // Literal values (parity with SubscriptionInvoiceStatus/WalletRegistrationStatus guarded
        // by Phase20BEnumParityTest).
        DB::statement(
            "ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_status_check
             CHECK (status IN ('draft','issued','pending_payment','partially_paid','paid','overdue','payment_failed','reconciliation_required','void'))"
        );

        DB::statement(
            "ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_wallet_registration_status_check
             CHECK (wallet_registration_status IN ('unregistered','pending','registered','failed'))"
        );

        // Money invariants (integer minor units; DB-authoritative).
        DB::statement(
            'ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_amounts_non_negative_check
             CHECK (subtotal_minor >= 0 AND discount_minor >= 0 AND total_minor >= 0 AND balance_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_discount_le_subtotal_check
             CHECK (discount_minor <= subtotal_minor)'
        );
        DB::statement(
            'ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_total_arithmetic_check
             CHECK (total_minor = subtotal_minor - discount_minor)'
        );
        DB::statement(
            'ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_balance_le_total_check
             CHECK (balance_minor <= total_minor)'
        );
        DB::statement(
            'ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_currency_upper_check
             CHECK (currency = upper(currency))'
        );
        DB::statement(
            'ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_period_dates_check
             CHECK (period_end > period_start)'
        );

        // Wallet status/null coherence (proves the 20B unregistered default; ADR-014).
        DB::statement(
            "ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_wallet_coherence_check
             CHECK (
                (wallet_registration_status = 'unregistered'
                    AND account_reference IS NULL AND wallet_payment_id IS NULL AND wallet_registered_at IS NULL)
                OR (wallet_registration_status = 'pending' AND wallet_registered_at IS NULL)
                OR (wallet_registration_status = 'registered'
                    AND account_reference IS NOT NULL AND wallet_payment_id IS NOT NULL AND wallet_registered_at IS NOT NULL)
                OR (wallet_registration_status = 'failed')
             )"
        );

        // Price belongs to plan (composite FK; snapshot).
        DB::statement(
            'ALTER TABLE subscription_invoices
             ADD CONSTRAINT subscription_invoices_price_plan_foreign
             FOREIGN KEY (price_id, plan_id)
             REFERENCES subscription_plan_prices (id, plan_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        // Gap-free per-merchant subscription-invoice number (many null-number drafts coexist).
        DB::statement(
            'CREATE UNIQUE INDEX subscription_invoices_merchant_invoice_number_unique
             ON subscription_invoices (merchant_id, invoice_number)
             WHERE invoice_number IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
