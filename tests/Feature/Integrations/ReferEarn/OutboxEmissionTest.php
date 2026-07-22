<?php

declare(strict_types=1);

use App\Domain\Integrations\ReferEarn\Actions\EnqueueProductEvent;
use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Integrations\ReferEarn\Jobs\DeliverReOutboxJob;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Integrations\ReferEarn\Support\CanonicalJson;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\JsonSchemaAssert;

uses(RefreshDatabase::class)->group('referearn', 'phase21ra', 'phase21ra-outbox');

/*
 | Outbox emission — Plan §58A.2, §13.17, §58B.1, §58B.2, §9 rules 22-23.
 |
 | Proves same-transaction atomicity, per-merchant sequence monotonicity, canonical-JSON hash
 | stability, the emission-scope data-minimization rule, and that every emitted payload validates
 | against its committed JSON Schema with no forbidden field.
 |
 | The queue is faked throughout: the test QUEUE_CONNECTION is `sync`, so a real dispatch would
 | deliver the event inline and every "still pending" assertion would silently be testing the
 | delivered state instead. Dispatch itself is asserted explicitly below.
 */

beforeEach(function (): void {
    Queue::fake();
});

function schemaFor(ReOutboundEventType $type): array
{
    return JsonSchemaAssert::load(base_path('docs/integrations/refer-earn/schemas/'.$type->schemaFile()));
}

function referredMerchant(ReferralSnapshotStatus $status = ReferralSnapshotStatus::Captured): Merchant
{
    $merchant = Merchant::factory()->create();

    ReferralSnapshot::factory()->create([
        'merchant_id' => $merchant->id,
        'snapshot_status' => $status,
        'code_normalized' => $status === ReferralSnapshotStatus::InvalidFormat ? null : 'SERVANA-X8T2K',
        'confirmed_at' => $status === ReferralSnapshotStatus::Confirmed ? now() : null,
    ]);

    return $merchant;
}

it('commits the event with its source fact and rolls back with it', function (): void {
    $merchant = referredMerchant();

    // Rollback: neither the fact nor the event survives.
    try {
        DB::transaction(function () use ($merchant): void {
            $merchant->forceFill(['name' => 'Renamed Studio'])->save();
            app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantStatusChanged, $merchant);

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    expect(ReOutboundEvent::query()->count())->toBe(0)
        ->and($merchant->fresh()?->name)->not->toBe('Renamed Studio');

    // Commit: both survive. (A distinct name, because the rolled-back attempt above left the
    // in-memory model already carrying 'Renamed Studio' — re-saving it would be a silent no-op.)
    DB::transaction(function () use ($merchant): void {
        $merchant->forceFill(['name' => 'Committed Studio'])->save();
        app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantStatusChanged, $merchant);
    });

    // TWO events, and that is the point: the explicit status-changed enqueue AND the
    // identity-snapshot event the name change triggers through MerchantIdentityObserver. Both were
    // absent after the rollback above, so both are genuinely bound to the source transaction.
    expect(ReOutboundEvent::query()->pluck('event_type')->map(fn (ReOutboundEventType $t): string => $t->value)->all())
        ->toEqualCanonicalizing(['merchant.identity_snapshot_changed', 'merchant.status_changed'])
        ->and($merchant->fresh()?->name)->toBe('Committed Studio');
});

it('emits nothing for an unreferred merchant', function (): void {
    $merchant = Merchant::factory()->create();

    DB::transaction(function () use ($merchant): void {
        expect(app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantRegistrationStarted, $merchant))->toBeNull();
    });

    expect(ReOutboundEvent::query()->count())->toBe(0);
});

it('applies the §58B.1 emission-scope rule per snapshot status', function (ReferralSnapshotStatus $status, bool $expected): void {
    $merchant = referredMerchant($status);

    DB::transaction(function () use ($merchant, $expected): void {
        $event = app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantRegistrationStarted, $merchant);

        expect($event !== null)->toBe($expected);
    });
})->with([
    'captured emits' => [ReferralSnapshotStatus::Captured, true],
    'validating emits' => [ReferralSnapshotStatus::Validating, true],
    'validated emits' => [ReferralSnapshotStatus::Validated, true],
    'confirmed emits' => [ReferralSnapshotStatus::Confirmed, true],
    'expired_unconfirmed emits' => [ReferralSnapshotStatus::ExpiredUnconfirmed, true],
    'invalid_format is silent' => [ReferralSnapshotStatus::InvalidFormat, false],
    'rejected is silent' => [ReferralSnapshotStatus::Rejected, false],
]);

it('allocates a monotonic per-merchant sequence and isolates merchants', function (): void {
    $first = referredMerchant();
    $second = referredMerchant();

    DB::transaction(function () use ($first, $second): void {
        app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantRegistrationStarted, $first);
        app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantAdminCreated, $first);
        app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantRegistrationStarted, $second);
        app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantSetupCompleted, $first);
    });

    expect(ReOutboundEvent::query()->where('merchant_id', $first->id)->orderBy('id')->pluck('sequence_no')->all())->toBe([1, 2, 3])
        // A second merchant's sequence starts at 1 again — ordering is per-merchant, not global.
        ->and(ReOutboundEvent::query()->where('merchant_id', $second->id)->pluck('sequence_no')->all())->toBe([1]);
});

it('computes content_sha256 over the canonical JSON of the stored payload', function (): void {
    $merchant = referredMerchant();

    $event = DB::transaction(fn (): ?ReOutboundEvent => app(EnqueueProductEvent::class)
        ->handle(ReOutboundEventType::MerchantRegistrationStarted, $merchant));

    expect($event)->not->toBeNull()
        ->and($event->content_sha256)->toBe(CanonicalJson::sha256($event->payload))
        ->and($event->delivery_status)->toBe(ReDeliveryStatus::Pending)
        ->and($event->attempt_count)->toBe(0)
        ->and($event->event_id)->toHaveLength(26);
});

it('produces a hash that is stable regardless of key order', function (): void {
    $ordered = ['alpha' => 1, 'beta' => ['b' => 2, 'a' => 1], 'gamma' => 'x'];
    $shuffled = ['gamma' => 'x', 'beta' => ['a' => 1, 'b' => 2], 'alpha' => 1];

    expect(CanonicalJson::sha256($ordered))->toBe(CanonicalJson::sha256($shuffled))
        ->and(CanonicalJson::encode($shuffled))->toBe('{"alpha":1,"beta":{"a":1,"b":2},"gamma":"x"}');
});

it('preserves list order because order is meaning in a list', function (): void {
    expect(CanonicalJson::encode(['xs' => [3, 1, 2]]))->toBe('{"xs":[3,1,2]}');
});

it('refuses to encode a float', function (): void {
    expect(fn () => CanonicalJson::encode(['amount' => 12.34]))->toThrow(RuntimeException::class, 'floats');
});

it('validates every emitted payload against its committed JSON Schema', function (ReOutboundEventType $type): void {
    $merchant = referredMerchant();

    if ($type === ReOutboundEventType::MerchantSetupCompleted) {
        $merchant->forceFill(['setup_completed_at' => now()])->save();
    }

    $context = match ($type) {
        ReOutboundEventType::MerchantStatusChanged => ['previous_status' => 'active'],
        ReOutboundEventType::MerchantIdentitySnapshotChanged => [
            'identity_snapshot_sha256' => hash('sha256', 'identity'),
            'changed_field_count' => 1,
        ],
        default => [],
    };

    $event = DB::transaction(fn (): ?ReOutboundEvent => app(EnqueueProductEvent::class)->handle($type, $merchant, $context));

    expect($event)->not->toBeNull();

    $violations = JsonSchemaAssert::violations(schemaFor($type), $event->payload);

    expect($violations)->toBe([], implode(' | ', $violations));
})->with(fn (): array => array_map(
    fn (ReOutboundEventType $t): array => [$t],
    ReOutboundEventType::cases(),
));

it('carries no forbidden field in any emitted payload', function (): void {
    // Plan §58B.2 forbidden list, plus the merchant's own identifying values so a regression that
    // starts spreading the model is caught by VALUE, not just by key name.
    $merchant = referredMerchant();
    $merchant->forceFill(['setup_completed_at' => now()])->save();
    $merchant->profile()->update(['contact_email' => 'owner@example.com', 'contact_phone' => '+254712345678']);

    $events = DB::transaction(function () use ($merchant): array {
        $enqueue = app(EnqueueProductEvent::class);

        return array_filter(array_map(
            fn (ReOutboundEventType $t): ?ReOutboundEvent => $enqueue->handle($t, $merchant, [
                'previous_status' => 'active',
                'identity_snapshot_sha256' => hash('sha256', 'identity'),
                'changed_field_count' => 1,
            ]),
            ReOutboundEventType::cases(),
        ));
    });

    $forbiddenKeys = [
        'client_name', 'client_phone', 'staff_name', 'staff_phone', 'email', 'phone', 'msisdn',
        'reason', 'suspension_reason', 'invoice_line', 'payment_reference', 'referral_code',
        'raw_code', 'raw_code_encrypted', 'signature', 'idempotency_key', 'sqlstate',
        'constraint', 'stack_trace', 'merchant_id', 'id',
    ];

    $forbiddenValues = [
        'owner@example.com', '+254712345678', 'SERVANA-X8T2K',
        (string) $merchant->id, $merchant->name,
    ];

    foreach ($events as $event) {
        foreach (array_keys($event->payload) as $key) {
            expect($forbiddenKeys)->not->toContain($key, "{$event->event_type->value} leaks {$key}");
        }

        $encoded = CanonicalJson::encode($event->payload);

        foreach ($forbiddenValues as $value) {
            expect($encoded)->not->toContain($value, "{$event->event_type->value} leaks the value {$value}");
        }
    }

    expect($events)->toHaveCount(count(ReOutboundEventType::cases()));
});

it('marks the delivery dispatch afterCommit so a rolled-back event is never delivered', function (): void {
    $merchant = referredMerchant();

    $event = DB::transaction(fn (): ?ReOutboundEvent => app(EnqueueProductEvent::class)
        ->handle(ReOutboundEventType::MerchantRegistrationStarted, $merchant));

    // The assertion is on the DISPATCH FLAG, not on push timing, and that distinction is the point:
    // `Queue::fake()` records a push immediately and ignores `afterCommit` entirely, so asserting
    // "nothing was pushed during a rolled-back transaction" would be testing the fake rather than
    // the guarantee. What actually protects a worker from an event whose source fact rolled back is
    // `afterCommit` on the real connection — so that is what is asserted here. The complementary
    // fact (a rolled-back transaction leaves no event ROW at all) is proven separately above.
    Queue::assertPushed(
        DeliverReOutboxJob::class,
        fn (DeliverReOutboxJob $job): bool => $job->outboundEventId === $event->id && $job->afterCommit === true,
    );

    Queue::assertPushed(DeliverReOutboxJob::class, 1);
});

it('dispatches no delivery job when the emission-scope rule suppresses the event', function (): void {
    $unreferred = Merchant::factory()->create();

    DB::transaction(function () use ($unreferred): void {
        app(EnqueueProductEvent::class)->handle(ReOutboundEventType::MerchantRegistrationStarted, $unreferred);
    });

    Queue::assertNotPushed(DeliverReOutboxJob::class);
});
