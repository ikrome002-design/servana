<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

uses()->group('audit', 'security', 'scheduler');

/*
 | Increment 7: audit:verify-chain is registered on the scheduler as a daily,
 | singleton, leader-only integrity check (Plan §67 scheduler, §1610 convention).
 */

function auditVerifyChainEvent(): ?Event
{
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        if (str_contains((string) $event->command, 'audit:verify-chain')) {
            return $event;
        }
    }

    return null;
}

it('registers audit:verify-chain on the scheduler', function (): void {
    expect(auditVerifyChainEvent())->not->toBeNull();
});

it('runs it daily, without overlapping, on one server', function (): void {
    $event = auditVerifyChainEvent();

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 0 * * *');            // daily
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->onOneServer)->toBeTrue();
});
