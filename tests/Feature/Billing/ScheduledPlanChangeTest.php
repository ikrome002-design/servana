<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\ApplyScheduledPlanChange;
use App\Domain\Billing\Actions\CancelScheduledPlanChange;
use App\Domain\Billing\Actions\SchedulePlanChange;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Enums\ScheduledPlanChangeStatus;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'phase20b-plan-change', 'scheduled-plan-change');

/** @return array{0:Merchant,1:MerchantSubscription,2:SubscriptionPlanPrice} */
function p20bscFixture(): array
{
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->bindForJob($merchant);
    $sub = MerchantSubscription::factory()->forMerchant($merchant)->status(S::Active)->create([
        'current_period_start' => '2026-07-01',
        'current_period_end' => '2026-08-01',
        'billing_interval' => 'monthly',
    ]);
    $targetPlan = SubscriptionPlan::factory()->create();
    $targetPrice = SubscriptionPlanPrice::factory()->create(['plan_id' => $targetPlan->id, 'billing_interval' => 'quarterly']);

    return [$merchant, $sub, $targetPrice];
}

function p20bscActor(): User
{
    return User::factory()->create();
}

it('schedules a plan change at the next current-period boundary', function (): void {
    [, $sub, $price] = p20bscFixture();

    $change = app(SchedulePlanChange::class)->handle($sub, $price, p20bscActor());

    expect($change->status)->toBe(ScheduledPlanChangeStatus::Scheduled)
        ->and($change->target_plan_id)->toBe($price->plan_id)
        ->and($change->target_price_id)->toBe($price->id)
        ->and($change->effective_at->toDateString())->toBe('2026-08-01');
});

it('rejects a second scheduled change for the same subscription and boundary', function (): void {
    [, $sub, $price] = p20bscFixture();
    app(SchedulePlanChange::class)->handle($sub, $price, p20bscActor());

    expect(fn () => app(SchedulePlanChange::class)->handle($sub->fresh(), $price, p20bscActor()))
        ->toThrow(QueryException::class);
});

it('cancels a scheduled change (scheduled -> cancelled)', function (): void {
    [, $sub, $price] = p20bscFixture();
    $change = app(SchedulePlanChange::class)->handle($sub, $price, p20bscActor());

    $cancelled = app(CancelScheduledPlanChange::class)->handle($change, p20bscActor());

    expect($cancelled->status)->toBe(ScheduledPlanChangeStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull();
});

it('rejects cancelling an already-applied change (422 invalid_state_transition)', function (): void {
    [, $sub, $price] = p20bscFixture();
    $change = app(SchedulePlanChange::class)->handle($sub, $price, p20bscActor());
    app(ApplyScheduledPlanChange::class)->handle($change, p20bscActor());

    expect(fn () => app(CancelScheduledPlanChange::class)->handle($change->fresh(), p20bscActor()))
        ->toThrow(BillingStateException::class);
});

it('applies at the next cycle with no proration and advances the period', function (): void {
    [, $sub, $price] = p20bscFixture();
    $change = app(SchedulePlanChange::class)->handle($sub, $price, p20bscActor());

    app(ApplyScheduledPlanChange::class)->handle($change, p20bscActor());

    $sub->refresh();
    expect($sub->plan_id)->toBe($price->plan_id)
        ->and($sub->price_id)->toBe($price->id)
        ->and($sub->billing_interval->value)->toBe('quarterly')
        // No proration: new period starts exactly at the old period end.
        ->and($sub->current_period_start->toDateString())->toBe('2026-08-01')
        // Quarterly = +3 months from the new start.
        ->and($sub->current_period_end->toDateString())->toBe('2026-11-01')
        ->and($change->fresh()->status)->toBe(ScheduledPlanChangeStatus::Applied);
});

it('applies exactly once — a replayed apply is a no-op', function (): void {
    [, $sub, $price] = p20bscFixture();
    $change = app(SchedulePlanChange::class)->handle($sub, $price, p20bscActor());

    app(ApplyScheduledPlanChange::class)->handle($change, p20bscActor());
    $periodEndAfterFirst = $sub->fresh()->current_period_end->toDateString();
    app(ApplyScheduledPlanChange::class)->handle($change->fresh(), p20bscActor()); // replay

    expect($sub->fresh()->current_period_end->toDateString())->toBe($periodEndAfterFirst)
        ->and($change->fresh()->status)->toBe(ScheduledPlanChangeStatus::Applied);
});

it('retains scheduled-change history after applying', function (): void {
    [$merchant, $sub, $price] = p20bscFixture();
    $change = app(SchedulePlanChange::class)->handle($sub, $price, p20bscActor());
    app(ApplyScheduledPlanChange::class)->handle($change, p20bscActor());

    expect(ScheduledPlanChange::query()->where('merchant_id', $merchant->id)->count())->toBe(1);
});
