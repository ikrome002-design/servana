<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\SubscriptionInvoiceItemType;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Enums\WalletRegistrationStatus;
use App\Domain\Billing\Exceptions\BillingModeNotSupportedException;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Queries\ResolveEffectivePlatformBillingSettings;
use App\Domain\Billing\Services\AllocateSubscriptionInvoiceNumber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Issue an immutable subscription invoice for a subscription's current period (Plan §49, §50; Gate
 * B3/B5; ADR-014; Phase 20B). System/action-driven — there is no merchant-facing issue route in 20B.
 *
 * Under the subscription + numbering row locks it: fails closed for any non-`fixed_amount` billing
 * mode (Gate B5 — no invoice, item, sequence, or audit); captures the subscription's plan+price;
 * allocates a gap-free per-merchant `subscription_invoice` number; creates the invoice (status
 * `issued`) plus a single immutable `plan_fee` line equal to the captured price; keeps `discount = 0`
 * (no promotions until 20C); sets the four Wallet columns to their 20B defaults (null / `unregistered`
 * — NO Wallet call, outbox, or registration); sets `issued_at`/`due_at`; and emits
 * `subscription_invoice.issued`.
 *
 * Idempotent per subscription period: a re-issue for the same merchant + period returns the existing
 * non-void invoice. Requires an active tenant context.
 */
final class IssueSubscriptionInvoice
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AllocateSubscriptionInvoiceNumber $allocator,
        private readonly ResolveEffectivePlatformBillingSettings $settings,
    ) {}

    public function handle(MerchantSubscription $subscription, ?User $actor = null): SubscriptionInvoice
    {
        return DB::transaction(function () use ($subscription, $actor): SubscriptionInvoice {
            $locked = MerchantSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            // Gate B5 — fail closed for any non-fixed billing mode (no rows created).
            $current = $this->settings->current();
            $mode = $current === null ? BillingMode::FixedAmount : $current->billing_mode;
            if ($mode !== BillingMode::FixedAmount) {
                throw BillingModeNotSupportedException::forMode($mode->value);
            }

            // Idempotency — one invoice per subscription period (non-void).
            $existing = SubscriptionInvoice::query()
                ->where('merchant_id', $locked->merchant_id)
                ->where('period_start', $locked->current_period_start)
                ->where('period_end', $locked->current_period_end)
                ->where('status', '!=', SubscriptionInvoiceStatus::Void->value)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            /** @var SubscriptionPlanPrice $price */
            $price = SubscriptionPlanPrice::query()->whereKey($locked->price_id)->firstOrFail();
            $subtotal = (int) $price->amount_minor;

            $number = $this->allocator->allocate($locked->merchant_id);
            $now = CarbonImmutable::now();

            $invoice = new SubscriptionInvoice;
            $invoice->merchant_id = $locked->merchant_id;
            $invoice->plan_id = $locked->plan_id;
            $invoice->price_id = $locked->price_id;
            $invoice->invoice_number = $number;
            $invoice->period_start = $locked->current_period_start;
            $invoice->period_end = $locked->current_period_end;
            $invoice->subtotal_minor = $subtotal;
            $invoice->discount_minor = 0; // no promotions until Phase 20C
            $invoice->total_minor = $subtotal;
            $invoice->currency = $price->currency;
            $invoice->balance_minor = $subtotal;
            $invoice->status = SubscriptionInvoiceStatus::Issued;
            // Phase 20B Wallet defaults (ADR-014) — no Wallet runtime writes these.
            $invoice->account_reference = null;
            $invoice->wallet_payment_id = null;
            $invoice->wallet_registration_status = WalletRegistrationStatus::Unregistered;
            $invoice->wallet_registered_at = null;
            $invoice->issued_at = $now;
            $invoice->due_at = CarbonImmutable::parse($locked->current_period_end)->endOfDay();
            $invoice->save();

            // Single immutable plan_fee line = captured price (fixed mode). No percentage/SMS/promotion.
            $item = new SubscriptionInvoiceItem;
            $item->merchant_id = $locked->merchant_id;
            $item->subscription_invoice_id = $invoice->id;
            $item->description = 'Subscription plan fee';
            $item->amount_minor = $subtotal;
            $item->type = SubscriptionInvoiceItemType::PlanFee;
            $item->save();

            $this->audit->record(AuditEvent::SubscriptionInvoiceIssued, $actor, $locked->merchant_id, null, $invoice, [
                'invoice_id' => $invoice->ulid,
                'invoice_number' => $number,
                'total_minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
            ]);

            return $invoice;
        });
    }
}
