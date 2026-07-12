<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * subscription_invoice_items — immutable invoice line items (Plan §13.9, §49; Phase 20B).
 * Merchant-owned (denormalized merchant_id for tenant isolation, matching the invoice_items
 * convention), with a composite FK guaranteeing the item's merchant matches its invoice.
 * Created at issuance; never edited or deleted. Phase 20B fixed mode issues a single
 * `plan_fee` line equal to the captured plan price; it fabricates no `platform_fee_rollup`
 * (20E), `sms_rollup` (21S), promotion, or Wallet amounts.
 *
 * `amount_minor` is an integer minor unit. `adjustment` items may be negative (documented
 * sign rule); all other types are non-negative. No adjustment/percentage/SMS lines are
 * created in Phase 20B.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('subscription_invoice_id')->constrained('subscription_invoices')->restrictOnDelete();
            $table->string('description');
            $table->bigInteger('amount_minor');
            $table->string('type', 24);
            $table->timestampsTz();

            $table->index('merchant_id');
            $table->index('subscription_invoice_id');
        });

        DB::statement(
            "ALTER TABLE subscription_invoice_items
             ADD CONSTRAINT subscription_invoice_items_type_check
             CHECK (type IN ('plan_fee','platform_fee_rollup','sms_rollup','adjustment'))"
        );

        // Sign rule: only `adjustment` lines may be negative; all others are non-negative.
        DB::statement(
            "ALTER TABLE subscription_invoice_items
             ADD CONSTRAINT subscription_invoice_items_amount_sign_check
             CHECK (type = 'adjustment' OR amount_minor >= 0)"
        );

        DB::statement(
            'ALTER TABLE subscription_invoice_items
             ADD CONSTRAINT subscription_invoice_items_description_not_blank_check
             CHECK (length(btrim(description)) > 0)'
        );

        // Tenant consistency: item's merchant matches its invoice's merchant.
        DB::statement(
            'ALTER TABLE subscription_invoice_items
             ADD CONSTRAINT subscription_invoice_items_invoice_merchant_foreign
             FOREIGN KEY (subscription_invoice_id, merchant_id)
             REFERENCES subscription_invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoice_items');
    }
};
