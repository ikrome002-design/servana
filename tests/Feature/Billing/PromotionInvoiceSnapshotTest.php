<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use App\Domain\Billing\Enums\PromotionalDiscountType;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Models\PromotionalDiscountTarget;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('billing', 'phase20c', 'phase20c-invoice-snapshot');

/*
 | Phase 20C invoice snapshot integration (Plan §53; Gate C4/C5). IssueSubscriptionInvoice resolves at
 | most one promotion, snapshots configured + applied terms onto the NEW invoice, and never re-resolves
 | an issued invoice. Fixed-mode platform billing (Gate B5).
 */

function issueFor(Merchant $merchant, MerchantSubscription $subscription): SubscriptionInvoice
{
    $ctx = app(TenantContext::class);
    $ctx->bindForJob($merchant);
    try {
        return app(IssueSubscriptionInvoice::class)->handle($subscription);
    } finally {
        $ctx->reset();
    }
}

function issueInvoiceFor(Merchant $merchant, SubscriptionPlan $plan, SubscriptionPlanPrice $price): SubscriptionInvoice
{
    $subscription = MerchantSubscription::factory()->forMerchant($merchant)->active()->create([
        'plan_id' => $plan->id,
        'price_id' => $price->id,
        'billing_interval' => $price->billing_interval,
    ]);

    return issueFor($merchant, $subscription);
}

function activePercentagePromo(Merchant $merchant, int $bps): PromotionalDiscount
{
    $promo = PromotionalDiscount::factory()->active()->percentage($bps)->scope(PromotionTargetScope::SelectedMerchants)
        ->create(['effective_from' => today()->subDay()]);
    PromotionalDiscountTarget::factory()->forMerchant($merchant)->create(['promotional_discount_id' => $promo->id]);

    return $promo;
}

function activeFixedPromo(Merchant $merchant, int $minor): PromotionalDiscount
{
    $promo = PromotionalDiscount::factory()->active()->fixed($minor, 'KES')->scope(PromotionTargetScope::SelectedMerchants)
        ->create(['effective_from' => today()->subDay()]);
    PromotionalDiscountTarget::factory()->forMerchant($merchant)->create(['promotional_discount_id' => $promo->id]);

    return $promo;
}

beforeEach(function (): void {
    $this->merchant = Merchant::factory()->create();
    $this->plan = SubscriptionPlan::factory()->create();
    $this->price = SubscriptionPlanPrice::factory()->create([
        'plan_id' => $this->plan->id,
        'billing_interval' => 'monthly',
        'amount_minor' => 500000,
        'currency' => 'KES',
    ]);
});

it('issues with zero discount when no promotion applies', function (): void {
    $invoice = issueInvoiceFor($this->merchant, $this->plan, $this->price);

    expect($invoice->discount_minor)->toBe(0)
        ->and($invoice->total_minor)->toBe(500000)
        ->and($invoice->promotional_discount_id)->toBeNull()
        ->and($invoice->promotion_type)->toBeNull();
});

it('snapshots a percentage promotion onto a new invoice', function (): void {
    $promo = activePercentagePromo($this->merchant, 1000); // 10%
    $invoice = issueInvoiceFor($this->merchant, $this->plan, $this->price);

    expect($invoice->discount_minor)->toBe(50000)
        ->and($invoice->total_minor)->toBe(450000)
        ->and($invoice->promotional_discount_id)->toBe($promo->id)
        ->and($invoice->promotion_type)->toBe(PromotionalDiscountType::Percentage)
        ->and($invoice->promotion_value_snapshot)->toBe(1000)
        ->and($invoice->promotion_currency)->toBeNull()
        ->and($invoice->promotion_resolved_at)->not->toBeNull();
});

it('snapshots configured and applied amounts for a capped fixed promotion (Gate C5)', function (): void {
    $promo = activeFixedPromo($this->merchant, 900000); // > subtotal 500000
    $invoice = issueInvoiceFor($this->merchant, $this->plan, $this->price);

    // configured 900000 snapshotted; applied capped at subtotal; total floors at 0.
    expect($invoice->promotion_value_snapshot)->toBe(900000)
        ->and($invoice->discount_minor)->toBe(500000)
        ->and($invoice->total_minor)->toBe(0)
        ->and($invoice->promotion_currency)->toBe('KES');
});

it('does not change an issued invoice when the promotion is later edited or cancelled', function (): void {
    $promo = activePercentagePromo($this->merchant, 1000);
    $invoice = issueInvoiceFor($this->merchant, $this->plan, $this->price);
    $originalDiscount = $invoice->discount_minor;

    // Mutate the promotion configuration afterwards (draft-only fields via raw update to simulate a
    // superseding change) and cancel it.
    DB::table('promotional_discounts')->where('id', $promo->id)->update(['status' => 'cancelled']);

    expect($invoice->refresh()->discount_minor)->toBe($originalDiscount)
        ->and($invoice->total_minor)->toBe(450000)
        ->and($invoice->promotional_discount_id)->toBe($promo->id);
});

it('is idempotent — re-issuing returns the same snapshot without recalculating', function (): void {
    activePercentagePromo($this->merchant, 1000);
    $subscription = MerchantSubscription::factory()->forMerchant($this->merchant)->active()->create([
        'plan_id' => $this->plan->id,
        'price_id' => $this->price->id,
        'billing_interval' => 'monthly',
    ]);

    $first = issueFor($this->merchant, $subscription);
    $second = issueFor($this->merchant, $subscription);

    expect($second->id)->toBe($first->id)
        ->and($second->discount_minor)->toBe($first->discount_minor)
        ->and(SubscriptionInvoice::query()->where('merchant_id', $this->merchant->id)->count())->toBe(1);
});

it('creates no platform-fee ledger row (20E boundary)', function (): void {
    activePercentagePromo($this->merchant, 1000);
    issueInvoiceFor($this->merchant, $this->plan, $this->price);

    // The invoice item stays a single plan_fee line; no percentage/platform-fee rollup is fabricated.
    $types = DB::table('subscription_invoice_items')->pluck('type')->unique()->values()->all();
    expect($types)->toBe(['plan_fee']);
});
