<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

uses()->group('billing', 'phase20b-scheduler', 'scheduler');

/*
 | Phase 20B: billing:process-subscription-lifecycle is registered as a daily, Africa/Nairobi,
 | singleton, leader-only lifecycle scheduler (Plan §54, §67; established billing cadence).
 */

function subscriptionLifecycleEvent(): ?Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, 'billing:process-subscription-lifecycle')) {
            return $event;
        }
    }

    return null;
}

it('registers billing:process-subscription-lifecycle on the scheduler', function (): void {
    expect(subscriptionLifecycleEvent())->not->toBeNull();
});

it('runs it daily in Africa/Nairobi, without overlapping, on one server', function (): void {
    $event = subscriptionLifecycleEvent();

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 0 * * *');                 // daily
    expect((string) $event->timezone)->toBe('Africa/Nairobi');
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->onOneServer)->toBeTrue();
});
