<?php

declare(strict_types=1);

use App\Domain\Integrations\ReferEarn\Actions\TransitionReferralSnapshot;
use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Exceptions\ReferralSnapshotStateException;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('referearn', 'phase21ra', 'phase21ra-state');

/*
 | Referral snapshot + outbox state machines (Plan §25.6, §58A.1, §58B.1, §58B.5 R-04).
 | Proves the enum machines match the documented specification exactly, that terminal states are
 | genuinely terminal, that the ONLY status writer is TransitionReferralSnapshot, and that the
 | emission-scope gate matches the §58B.1 data-minimization boundary.
 */

/** Every transition the referral snapshot machine permits, as (from, to) pairs. */
function allowedSnapshotTransitions(): array
{
    $allowed = [];

    foreach (ReferralSnapshotStatus::cases() as $from) {
        foreach (ReferralSnapshotStatus::cases() as $to) {
            if ($from->canTransitionTo($to)) {
                $allowed[] = $from->value.' -> '.$to->value;
            }
        }
    }

    sort($allowed);

    return $allowed;
}

it('permits exactly the documented referral snapshot transitions', function (): void {
    expect(allowedSnapshotTransitions())->toBe([
        'captured -> invalid_format',
        'captured -> validating',
        'validated -> confirmed',
        'validated -> expired_unconfirmed',
        // Plan §58B.5 R-04: a valid code whose ATTRIBUTION is refused at confirm time.
        'validated -> rejected',
        'validating -> rejected',
        'validating -> validated',
    ]);
});

it('has no self-transition anywhere (a retry is not a state change)', function (): void {
    foreach (ReferralSnapshotStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse("{$status->value} must not self-transition");
    }
});

it('makes every terminal snapshot state a dead end', function (): void {
    $terminal = array_filter(ReferralSnapshotStatus::cases(), fn (ReferralSnapshotStatus $s): bool => $s->isTerminal());

    expect($terminal)->toHaveCount(4);

    foreach ($terminal as $from) {
        foreach (ReferralSnapshotStatus::cases() as $to) {
            expect($from->canTransitionTo($to))->toBeFalse("{$from->value} is terminal but allows {$to->value}");
        }
    }
});

it('never allows a regression to an earlier state', function (): void {
    $order = [
        ReferralSnapshotStatus::Captured->value => 0,
        ReferralSnapshotStatus::Validating->value => 1,
        ReferralSnapshotStatus::Validated->value => 2,
        ReferralSnapshotStatus::Confirmed->value => 3,
        ReferralSnapshotStatus::Rejected->value => 3,
        ReferralSnapshotStatus::InvalidFormat->value => 3,
        ReferralSnapshotStatus::ExpiredUnconfirmed->value => 3,
    ];

    foreach (ReferralSnapshotStatus::cases() as $from) {
        foreach (ReferralSnapshotStatus::cases() as $to) {
            if ($from->canTransitionTo($to)) {
                expect($order[$to->value])->toBeGreaterThan($order[$from->value]);
            }
        }
    }
});

it('walks the happy path through the action', function (): void {
    $action = app(TransitionReferralSnapshot::class);
    $snapshot = ReferralSnapshot::factory()->create();

    $snapshot = $action->handle($snapshot, ReferralSnapshotStatus::Validating);
    expect($snapshot->snapshot_status)->toBe(ReferralSnapshotStatus::Validating);

    $snapshot = $action->handle($snapshot, ReferralSnapshotStatus::Validated, ['re_validation_result_code' => 'VALID']);
    expect($snapshot->snapshot_status)->toBe(ReferralSnapshotStatus::Validated)
        ->and($snapshot->re_validation_result_code)->toBe('VALID');

    $snapshot = $action->handle($snapshot, ReferralSnapshotStatus::Confirmed, ['re_attribution_public_id' => 'ATTR-XYZ']);

    expect($snapshot->snapshot_status)->toBe(ReferralSnapshotStatus::Confirmed)
        ->and($snapshot->re_attribution_public_id)->toBe('ATTR-XYZ')
        ->and($snapshot->confirmed_at)->not->toBeNull()
        ->and($snapshot->last_transition_at)->not->toBeNull();
});

it('rejects an invalid transition with a 422-shaped domain exception', function (): void {
    $snapshot = ReferralSnapshot::factory()->create();

    expect(fn () => app(TransitionReferralSnapshot::class)->handle($snapshot, ReferralSnapshotStatus::Confirmed))
        ->toThrow(ReferralSnapshotStateException::class);
});

it('refuses to move a confirmed snapshot at all', function (): void {
    $snapshot = ReferralSnapshot::factory()->confirmed()->create();

    foreach (ReferralSnapshotStatus::cases() as $target) {
        expect(fn () => app(TransitionReferralSnapshot::class)->handle($snapshot, $target))
            ->toThrow(ReferralSnapshotStateException::class);
    }
});

it('gates event emission on the §58B.1 data-minimization boundary', function (): void {
    $emitting = array_values(array_map(
        fn (ReferralSnapshotStatus $s): string => $s->value,
        array_filter(ReferralSnapshotStatus::cases(), fn (ReferralSnapshotStatus $s): bool => $s->permitsEventEmission()),
    ));

    sort($emitting);

    expect($emitting)->toBe(['captured', 'confirmed', 'expired_unconfirmed', 'validated', 'validating'])
        ->and(ReferralSnapshotStatus::InvalidFormat->permitsEventEmission())->toBeFalse()
        ->and(ReferralSnapshotStatus::Rejected->permitsEventEmission())->toBeFalse();
});

it('permits exactly the documented outbox delivery transitions', function (): void {
    $allowed = [];

    foreach (ReDeliveryStatus::cases() as $from) {
        foreach (ReDeliveryStatus::cases() as $to) {
            if ($from->canTransitionTo($to)) {
                $allowed[] = $from->value.' -> '.$to->value;
            }
        }
    }

    sort($allowed);

    expect($allowed)->toBe([
        'dead_letter -> superseded',
        'delivered -> superseded',
        'delivering -> dead_letter',
        'delivering -> delivered',
        'delivering -> pending',
        'pending -> delivering',
    ]);
});

it('treats delivered, dead_letter and superseded as terminal', function (): void {
    expect(ReDeliveryStatus::Delivered->isTerminal())->toBeTrue()
        ->and(ReDeliveryStatus::DeadLetter->isTerminal())->toBeTrue()
        ->and(ReDeliveryStatus::Superseded->isTerminal())->toBeTrue()
        ->and(ReDeliveryStatus::Pending->isTerminal())->toBeFalse()
        ->and(ReDeliveryStatus::Delivering->isTerminal())->toBeFalse()
        ->and(ReDeliveryStatus::Superseded->canTransitionTo(ReDeliveryStatus::Pending))->toBeFalse();
});
