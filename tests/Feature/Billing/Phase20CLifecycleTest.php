<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\ApproveFreePeriodOffer;
use App\Domain\Billing\Actions\ApprovePromotionalDiscount;
use App\Domain\Billing\Actions\CancelPromotionalDiscount;
use App\Domain\Billing\Actions\CreateFreePeriodOffer;
use App\Domain\Billing\Actions\CreatePromotionalDiscount;
use App\Domain\Billing\Actions\PausePromotionalDiscount;
use App\Domain\Billing\Actions\ResumePromotionalDiscount;
use App\Domain\Billing\Actions\UpdatePromotionalDiscountDraft;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('billing', 'phase20c', 'phase20c-lifecycle');

/*
 | Phase 20C lifecycle actions + scheduler (Plan §53; Gate C6). Drafts editable; approved terms
 | immutable; approval sets approver; pause/resume availability only; cancel from pre-active only; the
 | scheduler activates/expires idempotently and emits typed high-severity audit.
 */

beforeEach(function (): void {
    $this->actor = User::factory()->create();
    $this->merchant = Merchant::factory()->create();
});

/** @param array<string,mixed> $overrides */
function draftPromoAttributes(array $overrides = []): array
{
    return array_merge([
        'name' => 'Launch promo',
        'type' => 'percentage',
        'value' => 1500,
        'currency' => null,
        'target_scope' => 'selected_merchants',
        'effective_from' => today(),
        'effective_to' => null,
    ], $overrides);
}

it('creates a draft promotion with explicit targets and a created audit event', function (): void {
    $discount = app(CreatePromotionalDiscount::class)->handle(
        draftPromoAttributes(),
        [['target_type' => 'merchant', 'merchant_id' => $this->merchant->id]],
        $this->actor,
    );

    expect($discount->status)->toBe(PromotionStatus::Draft)
        ->and($discount->created_by)->toBe($this->actor->id)
        ->and($discount->targets()->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'promotion.created')->count())->toBe(1);
});

it('creates a global promotion with no target rows', function (): void {
    $discount = app(CreatePromotionalDiscount::class)->handle(
        draftPromoAttributes(['target_scope' => 'all_new_merchants']),
        [['target_type' => 'merchant', 'merchant_id' => $this->merchant->id]], // ignored for global scope
        $this->actor,
    );

    expect($discount->targets()->count())->toBe(0);
});

it('edits a draft and replaces its targets', function (): void {
    $discount = app(CreatePromotionalDiscount::class)->handle(
        draftPromoAttributes(),
        [['target_type' => 'merchant', 'merchant_id' => $this->merchant->id]],
        $this->actor,
    );
    $other = Merchant::factory()->create();

    $updated = app(UpdatePromotionalDiscountDraft::class)->handle(
        $discount,
        ['value' => 2000],
        [['target_type' => 'merchant', 'merchant_id' => $other->id]],
        $this->actor,
    );

    expect($updated->value)->toBe(2000)
        ->and($updated->targets()->count())->toBe(1)
        ->and($updated->targets()->first()->merchant_id)->toBe($other->id);
});

it('approves a current-window promotion straight to active', function (): void {
    $discount = app(CreatePromotionalDiscount::class)->handle(
        draftPromoAttributes(['effective_from' => today()->subDay()]),
        [['target_type' => 'merchant', 'merchant_id' => $this->merchant->id]],
        $this->actor,
    );

    $approved = app(ApprovePromotionalDiscount::class)->handle($discount, $this->actor, 'Launch approved');

    expect($approved->status)->toBe(PromotionStatus::Active)
        ->and($approved->approved_by)->toBe($this->actor->id)
        ->and($approved->approved_at)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'promotion.approved')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'promotion.activated')->count())->toBe(1);
});

it('approves a future-window promotion to scheduled', function (): void {
    $discount = app(CreatePromotionalDiscount::class)->handle(
        draftPromoAttributes(['effective_from' => today()->addDays(7)]),
        [['target_type' => 'merchant', 'merchant_id' => $this->merchant->id]],
        $this->actor,
    );

    $approved = app(ApprovePromotionalDiscount::class)->handle($discount, $this->actor, 'Scheduled launch');

    expect($approved->status)->toBe(PromotionStatus::Scheduled)
        ->and(DB::table('audit_logs')->where('action', 'promotion.activated')->count())->toBe(0);
});

it('makes approved terms immutable', function (): void {
    $discount = app(CreatePromotionalDiscount::class)->handle(
        draftPromoAttributes(['effective_from' => today()->subDay()]),
        [['target_type' => 'merchant', 'merchant_id' => $this->merchant->id]],
        $this->actor,
    );
    app(ApprovePromotionalDiscount::class)->handle($discount, $this->actor, 'Approved');

    expect(fn () => app(UpdatePromotionalDiscountDraft::class)->handle($discount->refresh(), ['value' => 9999], null, $this->actor))
        ->toThrow(BillingStateException::class);
});

it('pauses and resumes an active promotion', function (): void {
    $discount = app(CreatePromotionalDiscount::class)->handle(
        draftPromoAttributes(['effective_from' => today()->subDay()]),
        [['target_type' => 'merchant', 'merchant_id' => $this->merchant->id]],
        $this->actor,
    );
    app(ApprovePromotionalDiscount::class)->handle($discount, $this->actor, 'Approved');

    $paused = app(PausePromotionalDiscount::class)->handle($discount->refresh(), $this->actor, 'Temporary hold');
    expect($paused->status)->toBe(PromotionStatus::Paused);

    $resumed = app(ResumePromotionalDiscount::class)->handle($paused, $this->actor, 'Back on');
    expect($resumed->status)->toBe(PromotionStatus::Active)
        ->and(DB::table('audit_logs')->where('action', 'promotion.paused')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'promotion.resumed')->count())->toBe(1);
});

it('rejects resume after the effective window has ended', function (): void {
    $discount = PromotionalDiscount::factory()->paused()->scope(PromotionTargetScope::SelectedMerchants)
        ->create(['effective_from' => today()->subDays(30), 'effective_to' => today()->subDay()]);

    expect(fn () => app(ResumePromotionalDiscount::class)->handle($discount, $this->actor, 'Too late'))
        ->toThrow(BillingStateException::class);
});

it('cancels a scheduled promotion but not an active one', function (): void {
    $scheduled = PromotionalDiscount::factory()->scheduled()->scope(PromotionTargetScope::SelectedMerchants)->create();
    $cancelled = app(CancelPromotionalDiscount::class)->handle($scheduled, $this->actor, 'Abandoned');
    expect($cancelled->status)->toBe(PromotionStatus::Cancelled);

    $active = PromotionalDiscount::factory()->active()->scope(PromotionTargetScope::SelectedMerchants)->create();
    expect(fn () => app(CancelPromotionalDiscount::class)->handle($active, $this->actor, 'Nope'))
        ->toThrow(BillingStateException::class);
});

it('approves a free-period offer to scheduled, never active', function (): void {
    $offer = app(CreateFreePeriodOffer::class)->handle(
        ['name' => 'Free 30', 'free_period_days' => 30, 'target_scope' => 'all_new_merchants', 'effective_from' => today()->subDay(), 'effective_to' => null],
        [],
        $this->actor,
    );

    $approved = app(ApproveFreePeriodOffer::class)->handle($offer, $this->actor, 'Approved');

    expect($approved->status)->toBe(FreePeriodOfferStatus::Scheduled)
        ->and(DB::table('audit_logs')->where('action', 'free_period_offer.approved')->count())->toBe(1);
});

it('activates due scheduled records and is idempotent', function (): void {
    $promo = PromotionalDiscount::factory()->scheduled()->scope(PromotionTargetScope::AllNewMerchants)
        ->create(['effective_from' => today()->subDay()]);
    $offer = FreePeriodOffer::factory()->scheduled()->scope(PromotionTargetScope::AllNewMerchants)
        ->create(['effective_from' => today()->subDay()]);

    expect(Artisan::call('billing:process-promotion-lifecycle'))->toBe(0);

    expect($promo->refresh()->status)->toBe(PromotionStatus::Active)
        ->and($offer->refresh()->status)->toBe(FreePeriodOfferStatus::Active)
        ->and(DB::table('audit_logs')->where('action', 'promotion.activated')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'free_period_offer.activated')->count())->toBe(1);

    // Second run — no further transition, no duplicate audit (exactly-once).
    expect(Artisan::call('billing:process-promotion-lifecycle'))->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'promotion.activated')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'free_period_offer.activated')->count())->toBe(1);
});

it('expires due active records and is idempotent', function (): void {
    $promo = PromotionalDiscount::factory()->active()->scope(PromotionTargetScope::AllNewMerchants)
        ->create(['effective_from' => today()->subDays(30), 'effective_to' => today()->subDay()]);

    expect(Artisan::call('billing:process-promotion-lifecycle'))->toBe(0);
    expect($promo->refresh()->status)->toBe(PromotionStatus::Expired)
        ->and(DB::table('audit_logs')->where('action', 'promotion.expired')->count())->toBe(1);

    expect(Artisan::call('billing:process-promotion-lifecycle'))->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'promotion.expired')->count())->toBe(1);
});
