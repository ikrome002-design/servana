<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * billing_escalation_events — append-only overdue-escalation log (Plan §13.15, §54; Phase 20B).
 * Merchant-owned. Drives and records the shared escalation pathway and the paired
 * `merchants.billing_status` projection. APPEND-ONLY: `created_at` only (no `updated_at`); no
 * application UPDATE/DELETE path.
 *
 * Gate B4: durable idempotency is enforced by UNIQUE(merchant_subscription_id, event_type,
 * period_boundary) — NEVER by `created_at`. `period_boundary` is the computed current-period
 * boundary date the event pertains to. See docs/architecture/state-machines/billing-escalation.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_escalation_events', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('subscription_invoice_id')->nullable()->constrained('subscription_invoices')->restrictOnDelete();
            $table->foreignId('merchant_subscription_id')->constrained('merchant_subscriptions')->restrictOnDelete();
            $table->string('event_type', 24);
            $table->string('from_billing_status', 20)->nullable();
            $table->string('to_billing_status', 20)->nullable();
            $table->text('reason')->nullable();
            $table->date('period_boundary');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('merchant_id');
            $table->index('merchant_subscription_id');
        });

        // Literal values (parity with BillingEscalationEventType guarded by Phase20BEnumParityTest).
        DB::statement(
            "ALTER TABLE billing_escalation_events
             ADD CONSTRAINT billing_escalation_events_event_type_check
             CHECK (event_type IN ('reminder','grace_entered','overdue','suspended_billing','recovered'))"
        );

        // Gate B4 — durable idempotency boundary (NOT created_at).
        DB::statement(
            'ALTER TABLE billing_escalation_events
             ADD CONSTRAINT billing_escalation_events_idempotency_unique
             UNIQUE (merchant_subscription_id, event_type, period_boundary)'
        );

        // Tenant consistency: the subscription belongs to the same merchant.
        DB::statement(
            'ALTER TABLE billing_escalation_events
             ADD CONSTRAINT billing_escalation_events_subscription_merchant_foreign
             FOREIGN KEY (merchant_subscription_id, merchant_id)
             REFERENCES merchant_subscriptions (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        // Tenant consistency for the optional invoice link (enforced only when present).
        DB::statement(
            'ALTER TABLE billing_escalation_events
             ADD CONSTRAINT billing_escalation_events_invoice_merchant_foreign
             FOREIGN KEY (subscription_invoice_id, merchant_id)
             REFERENCES subscription_invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_escalation_events');
    }
};
