<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionalDiscountType;
use App\Domain\Billing\Enums\SubscriptionInvoiceItemType;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Enums\WalletRegistrationStatus;
use App\Domain\Billing\Exceptions\BillingModeNotSupportedException;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Queries\ResolveEffectivePlatformBillingSettings;
use App\Domain\Billing\Queries\ResolvePromotionalDiscount;
use App\Domain\Billing\Services\AggregatePlatformFeesIntoSubscriptionInvoice;
use App\Domain\Billing\Services\AllocateSubscriptionInvoiceNumber;
use App\Domain\Billing\Services\BillingIntervalCalculator;
use App\Domain\Billing\Services\CalculatePromotionalDiscount;
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
        private readonly ResolvePromotionalDiscount $promotions,
        private readonly CalculatePromotionalDiscount $calculator,
        private readonly AggregatePlatformFeesIntoSubscriptionInvoice $platformFees,
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
            $planSubtotal = (int) $price->amount_minor;

            // Phase 20C — resolve at most one promotional discount at the issuance business date
            // (Gate C3) against this invoice's merchant/plan/effective (fixed) mode. Snapshot both the
            // configured value and the applied (capped) amount (Gate C4/C5); percentage uses bps +
            // ADR-005; fixed is capped at the PLAN subtotal so the total never goes negative. No
            // promotion ⇒ zero discount. Later promotion edits never re-resolve this issued invoice.
            // The promotion applies to the subscription plan fee only — the platform-fee rollup is a
            // pass-through liability and is never discounted.
            $issuanceDate = CarbonImmutable::now(BillingIntervalCalculator::TIMEZONE);
            $promotion = $this->promotions->resolve($locked->merchant_id, $locked->plan_id, $mode, $issuanceDate);
            $discount = $promotion !== null
                ? $this->calculator->calculate($promotion, $planSubtotal, $price->currency)
                : 0;

            // Phase 20E — collect + FOR UPDATE-lock the eligible earned/pending platform-fee liabilities
            // for this merchant + currency + Africa/Nairobi billing period, and fold their integer total
            // into the (immutable) subtotal before issuance. Zero eligible entries ⇒ zero rollup ⇒ the
            // invoice is exactly the Phase 20B plan-fee invoice (backward compatible). No Wallet runtime.
            $feeSelection = $this->platformFees->collectEligible(
                $locked->merchant_id,
                $price->currency,
                CarbonImmutable::parse((string) $locked->current_period_start),
                CarbonImmutable::parse((string) $locked->current_period_end),
            );

            // Phase 20E future-cycle closure — sweep the pending platform-fee CORRECTIONS (reversals /
            // adjustments of already-invoiced fees) into one signed `adjustment` line. The applied net is
            // capped at the invoice's positive charges (headroom = plan + rollup − discount) so the total
            // can never go negative (DB-enforced); corrections that do not fit stay pending and carry to a
            // later cycle. Never a Wallet credit (that is Phase 20D-W).
            $base = $planSubtotal + $feeSelection->totalMinor;
            $correctionSelection = $this->platformFees->collectApplicableCorrections(
                $locked->merchant_id,
                $price->currency,
                CarbonImmutable::parse((string) $locked->current_period_end),
                $base - $discount,
            );

            $subtotal = $base + $correctionSelection->netMinor;
            $total = $subtotal - $discount;

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
            $invoice->discount_minor = $discount;
            $invoice->total_minor = $total;
            $invoice->currency = $price->currency;
            $invoice->balance_minor = $total;
            if ($promotion !== null) {
                $invoice->promotional_discount_id = $promotion->id;
                $invoice->promotion_type = $promotion->type;
                $invoice->promotion_value_snapshot = $promotion->value;
                $invoice->promotion_currency = $promotion->type === PromotionalDiscountType::FixedAmount
                    ? $promotion->currency
                    : null;
                $invoice->promotion_resolved_at = $now;
            }
            $invoice->status = SubscriptionInvoiceStatus::Issued;
            // Phase 20B Wallet defaults (ADR-014) — no Wallet runtime writes these.
            $invoice->account_reference = null;
            $invoice->wallet_payment_id = null;
            $invoice->wallet_registration_status = WalletRegistrationStatus::Unregistered;
            $invoice->wallet_registered_at = null;
            $invoice->issued_at = $now;
            $invoice->due_at = CarbonImmutable::parse($locked->current_period_end)->endOfDay();
            $invoice->save();

            // Immutable plan_fee line = captured plan price. No percentage/SMS/promotion on this line.
            $item = new SubscriptionInvoiceItem;
            $item->merchant_id = $locked->merchant_id;
            $item->subscription_invoice_id = $invoice->id;
            $item->description = 'Subscription plan fee';
            $item->amount_minor = $planSubtotal;
            $item->type = SubscriptionInvoiceItemType::PlanFee;
            $item->save();

            // Phase 20E — write the single platform_fee_rollup line (if any) and transition the linked
            // earned entries pending → aggregated → invoiced, inside this same issuance transaction. A
            // rollback (e.g. a duplicate-rollup guard violation) undoes the invoice, number, and links.
            $rollupItem = $this->platformFees->writeRollup($invoice, $feeSelection, $actor);

            // Phase 20E future-cycle closure — write the signed correction `adjustment` line (if any) and
            // transition the consumed correction entries pending → aggregated → invoiced, inside this same
            // transaction. A rollback undoes the invoice, number, rollup, and every correction link.
            $correctionItem = $this->platformFees->writeCorrectionLine($invoice, $correctionSelection, $actor);

            $this->audit->record(AuditEvent::SubscriptionInvoiceIssued, $actor, $locked->merchant_id, null, $invoice, [
                'invoice_id' => $invoice->ulid,
                'invoice_number' => $number,
                'subtotal_minor' => $invoice->subtotal_minor,
                'discount_minor' => $invoice->discount_minor,
                'total_minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
                'promotion_id' => $promotion?->ulid,
                'platform_fee_rollup_minor' => $feeSelection->totalMinor,
                'platform_fee_entry_count' => $feeSelection->count(),
                'platform_fee_rollup_item_id' => $rollupItem?->ulid,
                'platform_fee_correction_net_minor' => $correctionSelection->netMinor,
                'platform_fee_correction_entry_count' => $correctionSelection->count(),
                'platform_fee_correction_item_id' => $correctionItem?->ulid,
                'platform_fee_correction_residual_count' => $correctionSelection->residualEntryCount,
            ]);

            return $invoice;
        });
    }
}
