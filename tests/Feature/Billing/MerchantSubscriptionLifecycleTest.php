<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Actions\ActivateSubscription;
use App\Domain\Billing\Actions\CancelSubscription;
use App\Domain\Billing\Actions\CreateTrialSubscription;
use App\Domain\Billing\Actions\EnterReadOnlyGrace;
use App\Domain\Billing\Actions\ExpireSubscription;
use App\Domain\Billing\Actions\MarkSubscriptionOverdue;
use App\Domain\Billing\Actions\SuspendSubscriptionForBilling;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('billing', 'phase20b-lifecycle', 'billing-status');

function p20blBind(Merchant $merchant): void
{
    app(TenantContext::class)->bindForJob($merchant);
}

function p20blSettings(int $trialDays): void
{
    PlatformBillingSettings::factory()->create([
        'default_trial_days' => $trialDays,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);
}

/** @return array{0:Merchant,1:SubscriptionPlanPrice} */
function p20blTrialFixture(?CarbonImmutable $adminCreatedAt = null): array
{
    $merchant = Merchant::factory()->create();
    MerchantUser::factory()->create([
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
        'created_at' => $adminCreatedAt ?? CarbonImmutable::now()->subDays(3),
    ]);
    $plan = SubscriptionPlan::factory()->create();
    $price = SubscriptionPlanPrice::factory()->create(['plan_id' => $plan->id, 'billing_interval' => 'monthly']);
    p20blBind($merchant);

    return [$merchant, $price];
}

/** @return array{0:Merchant,1:MerchantSubscription} */
function p20blSubInState(S $status): array
{
    $merchant = Merchant::factory()->create();
    p20blBind($merchant);
    $sub = MerchantSubscription::factory()->forMerchant($merchant)->status($status)->create();

    return [$merchant, $sub];
}

function p20blActor(): User
{
    return User::factory()->create();
}

// ─── CreateTrialSubscription (Gate B1) ──────────────────────────────────────

it('anchors the trial to the founding Merchant-Admin creation time and snapshots trial days', function (): void {
    $adminCreated = CarbonImmutable::parse('2026-06-01 09:00:00'); // app default timezone
    p20blSettings(14);
    [$merchant, $price] = p20blTrialFixture($adminCreated);
    $foundingAdmin = MerchantUser::query()->where('merchant_id', $merchant->id)->where('role', MerchantUserRole::MerchantAdmin)->sole();

    $sub = app(CreateTrialSubscription::class)->handle($merchant, $price, p20blActor());

    expect($sub->status)->toBe(S::Trialing)
        ->and($sub->trial_days_snapshot)->toBe(14)
        // Gate B1: anchored to the founding Merchant-Admin membership creation instant.
        ->and($sub->trial_started_at->equalTo($foundingAdmin->created_at))->toBeTrue()
        ->and($sub->trial_ends_at->toDateString())->toBe('2026-06-15')
        ->and($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::Trialing);
});

it('does not rewrite the trial-days snapshot when platform settings later change', function (): void {
    p20blSettings(14);
    [$merchant, $price] = p20blTrialFixture();
    $sub = app(CreateTrialSubscription::class)->handle($merchant, $price, p20blActor());

    // A later effective settings version raises default_trial_days to 30.
    PlatformBillingSettings::factory()->create([
        'default_trial_days' => 30,
        'effective_from' => CarbonImmutable::now()->addDay(),
    ]);

    expect($sub->fresh()->trial_days_snapshot)->toBe(14);
});

it('is idempotent — a replayed setup creates no duplicate current subscription', function (): void {
    p20blSettings(14);
    [$merchant, $price] = p20blTrialFixture();
    $action = app(CreateTrialSubscription::class);

    $first = $action->handle($merchant, $price, p20blActor());
    $second = $action->handle($merchant, $price, p20blActor());

    expect($second->id)->toBe($first->id)
        ->and(MerchantSubscription::query()->where('merchant_id', $merchant->id)->count())->toBe(1);
});

// ─── Projection mapping ─────────────────────────────────────────────────────

it('projects active on activation', function (): void {
    [$merchant, $sub] = p20blSubInState(S::Trialing);
    app(ActivateSubscription::class)->handle($sub, p20blActor());
    expect($sub->fresh()->status)->toBe(S::Active)
        ->and($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::Active);
});

it('projects read_only_grace on grace entry', function (): void {
    [$merchant, $sub] = p20blSubInState(S::Active);
    app(EnterReadOnlyGrace::class)->handle($sub, p20blActor());
    expect($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::ReadOnlyGrace);
});

it('projects overdue then suspended', function (): void {
    [$merchant, $sub] = p20blSubInState(S::Active);
    app(MarkSubscriptionOverdue::class)->handle($sub, p20blActor());
    expect($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::Overdue);
    app(SuspendSubscriptionForBilling::class)->handle($sub->fresh(), p20blActor());
    expect($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::SuspendedBilling);
});

it('projects suspended_billing with subscription_cancelled on cancel (Gate B2)', function (): void {
    [$merchant, $sub] = p20blSubInState(S::Active);
    app(CancelSubscription::class)->handle($sub, p20blActor());

    $merchant->refresh();
    expect($sub->fresh()->status)->toBe(S::Cancelled)
        ->and($sub->fresh()->cancelled_at)->not->toBeNull()
        ->and($merchant->billing_status)->toBe(MerchantBillingStatus::SuspendedBilling)
        ->and($merchant->billing_status_reason)->toBe('subscription_cancelled');
});

it('projects suspended_billing with subscription_expired on expiry (Gate B2)', function (): void {
    [$merchant, $sub] = p20blSubInState(S::Trialing);
    app(ExpireSubscription::class)->handle($sub, p20blActor());

    $merchant->refresh();
    expect($sub->fresh()->status)->toBe(S::Expired)
        ->and($merchant->billing_status)->toBe(MerchantBillingStatus::SuspendedBilling)
        ->and($merchant->billing_status_reason)->toBe('subscription_expired');
});

it('keeps cancelled and expired reasons distinct', function (): void {
    [$m1, $s1] = p20blSubInState(S::Active);
    app(CancelSubscription::class)->handle($s1, p20blActor());
    [$m2, $s2] = p20blSubInState(S::Active);
    app(ExpireSubscription::class)->handle($s2, p20blActor());

    expect($m1->fresh()->billing_status_reason)->toBe('subscription_cancelled')
        ->and($m2->fresh()->billing_status_reason)->toBe('subscription_expired');
});

it('does not project early for a future-dated cancellation (Gate B2)', function (): void {
    [$merchant, $sub] = p20blSubInState(S::Active);
    $merchant->billing_status = MerchantBillingStatus::Active;
    $merchant->save();

    app(CancelSubscription::class)->handle($sub, p20blActor(), CarbonImmutable::now()->addDays(20));

    expect($sub->fresh()->status)->toBe(S::Active)
        ->and($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::Active);
});

// ─── Independence + rollback ────────────────────────────────────────────────

it('never changes operational merchants.status during a billing transition', function (): void {
    [$merchant, $sub] = p20blSubInState(S::Active);
    $merchant->status = MerchantStatus::Suspended; // e.g. fraud/manual operational suspension
    $merchant->save();

    app(SuspendSubscriptionForBilling::class)->handle($sub, p20blActor());

    expect($merchant->fresh()->status)->toBe(MerchantStatus::Suspended)
        ->and($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::SuspendedBilling);
});

it('rolls back subscription, projection, and audit atomically on an invalid transition', function (): void {
    [$merchant, $sub] = p20blSubInState(S::Cancelled);
    $merchant->billing_status = MerchantBillingStatus::SuspendedBilling;
    $merchant->billing_status_reason = 'subscription_cancelled';
    $merchant->save();
    $auditBefore = DB::table('audit_logs')->where('action', AuditEvent::MerchantBillingStatusChanged->value)->count();

    expect(fn () => app(ActivateSubscription::class)->handle($sub, p20blActor()))
        ->toThrow(BillingStateException::class);

    expect($sub->fresh()->status)->toBe(S::Cancelled)
        ->and($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::SuspendedBilling)
        ->and(DB::table('audit_logs')->where('action', AuditEvent::MerchantBillingStatusChanged->value)->count())->toBe($auditBefore);
});

it('emits the billing_status_changed audit event on a successful projection', function (): void {
    [$merchant, $sub] = p20blSubInState(S::Trialing);
    app(ActivateSubscription::class)->handle($sub, p20blActor());

    expect(DB::table('audit_logs')->where('action', AuditEvent::SubscriptionActivated->value)->where('merchant_id', $merchant->id)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', AuditEvent::MerchantBillingStatusChanged->value)->where('merchant_id', $merchant->id)->exists())->toBeTrue();
});

it('emits subscription.recovered when recovering from suspended_billing', function (): void {
    [$merchant, $sub] = p20blSubInState(S::SuspendedBilling);
    app(ActivateSubscription::class)->handle($sub, p20blActor());

    expect($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::Active)
        ->and(DB::table('audit_logs')->where('action', AuditEvent::SubscriptionRecovered->value)->where('merchant_id', $merchant->id)->exists())->toBeTrue();
});
