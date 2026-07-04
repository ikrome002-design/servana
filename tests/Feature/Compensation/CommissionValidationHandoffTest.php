<?php

declare(strict_types=1);

use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentValidationEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('payments', 'compensation');

it('writes one durable per-component validated_allocation handoff at validation, with no invented rate', function (): void {
    Queue::fake();
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [
        cashComponent(150000),
        referencedComponent(350000, 'mpesa_offline', 'QGX7YT1ABC'),
    ]);

    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();

    $event = PaymentValidationEvent::query()->firstOrFail();
    $handoffs = CommissionHandoffEvent::query()->where('kind', 'validated_allocation')->get();

    // One per component, each linked to the validation event + its component.
    expect($handoffs)->toHaveCount(2)
        ->and($handoffs->pluck('payment_validation_event_id')->unique()->all())->toBe([$event->id])
        ->and($handoffs->pluck('amount_minor')->sort()->values()->all())->toBe([150000, 350000])
        ->and($handoffs->pluck('payment_record_id')->all())
        ->toEqualCanonicalizing(PaymentRecord::query()->pluck('id')->all());

    // It is a SEAM, not a ledger: no rate/earned/payable columns exist on the table.
    expect(Schema::hasColumn('commission_handoff_events', 'rate'))->toBeFalse()
        ->and(Schema::hasColumn('commission_handoff_events', 'earned_minor'))->toBeFalse()
        ->and(Schema::hasColumn('commission_handoff_events', 'payable_minor'))->toBeFalse();

    // Every handoff is unconsumed (Phase 20G consumes it later).
    expect($handoffs->whereNotNull('consumed_at'))->toBeEmpty();
});

it('is idempotent per (validation event, component): a duplicate handoff is rejected by the partial unique index', function (): void {
    Queue::fake();
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);
    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();

    $handoff = CommissionHandoffEvent::query()->where('kind', 'validated_allocation')->firstOrFail();

    // A second validated_allocation row for the same (event, component) violates the index.
    expect(fn () => CommissionHandoffEvent::factory()->create([
        'kind' => 'validated_allocation',
        'payment_validation_event_id' => $handoff->payment_validation_event_id,
        'payment_record_id' => $handoff->payment_record_id,
        'merchant_id' => $handoff->merchant_id,
        'branch_id' => $handoff->branch_id,
        'invoice_id' => $handoff->invoice_id,
    ]))->toThrow(QueryException::class);
});
