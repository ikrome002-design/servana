<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Actions\RecordBillingEscalationEvent;
use App\Domain\Billing\Enums\BillingEscalationEventType;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Models\BillingEscalationEvent;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('billing', 'phase20b-escalation', 'billing-escalation');

function p20beSub(): MerchantSubscription
{
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->bindForJob($merchant);

    return MerchantSubscription::factory()->forMerchant($merchant)->status(S::Active)->create();
}

it('records an append-only escalation event and emits its typed audit event', function (): void {
    $sub = p20beSub();
    $boundary = CarbonImmutable::parse('2026-08-01');

    $event = app(RecordBillingEscalationEvent::class)->handle(
        $sub, BillingEscalationEventType::Overdue, $boundary,
        MerchantBillingStatus::Active, MerchantBillingStatus::Overdue, 'past due',
    );

    expect($event->event_type)->toBe(BillingEscalationEventType::Overdue)
        ->and($event->period_boundary->toDateString())->toBe('2026-08-01')
        ->and(DB::table('audit_logs')->where('action', AuditEvent::BillingEscalationOverdue->value)->where('merchant_id', $sub->merchant_id)->exists())->toBeTrue();
});

it('is idempotent per (subscription, event_type, period_boundary)', function (): void {
    $sub = p20beSub();
    $boundary = CarbonImmutable::parse('2026-08-01');
    $action = app(RecordBillingEscalationEvent::class);

    $first = $action->handle($sub, BillingEscalationEventType::Overdue, $boundary);
    $second = $action->handle($sub, BillingEscalationEventType::Overdue, $boundary);

    expect($second->id)->toBe($first->id)
        ->and(BillingEscalationEvent::query()->where('merchant_subscription_id', $sub->id)->count())->toBe(1)
        // audit emitted only once (on the first insert).
        ->and(DB::table('audit_logs')->where('action', AuditEvent::BillingEscalationOverdue->value)->where('merchant_id', $sub->merchant_id)->count())->toBe(1);
});

it('allows the same event type for a different period boundary', function (): void {
    $sub = p20beSub();
    $action = app(RecordBillingEscalationEvent::class);

    $action->handle($sub, BillingEscalationEventType::Overdue, CarbonImmutable::parse('2026-08-01'));
    $action->handle($sub, BillingEscalationEventType::Overdue, CarbonImmutable::parse('2026-09-01'));

    expect(BillingEscalationEvent::query()->where('merchant_subscription_id', $sub->id)->count())->toBe(2);
});

it('is append-only — the log has no updated_at and rows are never updated', function (): void {
    $sub = p20beSub();
    app(RecordBillingEscalationEvent::class)->handle($sub, BillingEscalationEventType::GraceEntered, CarbonImmutable::parse('2026-08-01'));

    expect(Schema::hasColumn('billing_escalation_events', 'updated_at'))->toBeFalse();
});
