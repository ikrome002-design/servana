<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Enums\PersonnelAvailabilityState;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Services\AvailabilityResolver;
use App\Domain\Scheduling\ValueObjects\TimeInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'availability-resolver');

/*
 | THE single deterministic availability resolver (Plan §13.7, §80 Phase 15B).
 | Recurring (weekday) vs exception (exact date); half-open [start,end); exceptions
 | beat recurring; unavailable beats available within a layer; Africa/Nairobi.
 */

function availStaff(): StaffProfile
{
    // Branch must belong to the same merchant for the composite-FK consistency
    // constraint on personnel_availability to hold.
    $merchant = Merchant::factory()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    return StaffProfile::factory()->create([
        'merchant_id' => $merchant->id,
        'primary_branch_id' => $branch->id,
    ]);
}

/** Insert one canonical availability row directly (controls exact values). */
function availRow(StaffProfile $staff, array $attrs): PersonnelAvailability
{
    return PersonnelAvailability::query()->create(array_merge([
        'merchant_id' => $staff->merchant_id,
        'branch_id' => $staff->primary_branch_id,
        'staff_profile_id' => $staff->id,
    ], $attrs));
}

function resolver(): AvailabilityResolver
{
    return app(AvailabilityResolver::class);
}

/** A deterministic business date + its Nairobi weekday. */
function businessDate(string $date = '2026-07-06'): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Africa/Nairobi');
}

function interval(string $start, string $end): TimeInterval
{
    return TimeInterval::fromStrings($start, $end);
}

it('permits a fully contained interval inside one recurring shift', function (): void {
    $staff = availStaff();
    $date = businessDate();
    availRow($staff, ['type' => 'recurring', 'weekday' => $date->dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('10:00', '11:00')))->toBeTrue();
});

it('rejects an interval before the shift', function (): void {
    $staff = availStaff();
    $date = businessDate();
    availRow($staff, ['type' => 'recurring', 'weekday' => $date->dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('08:00', '09:00')))->toBeFalse();
});

it('rejects an interval after the shift', function (): void {
    $staff = availStaff();
    $date = businessDate();
    availRow($staff, ['type' => 'recurring', 'weekday' => $date->dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('17:00', '18:00')))->toBeFalse();
});

it('rejects an interval extending past shift end', function (): void {
    $staff = availStaff();
    $date = businessDate();
    availRow($staff, ['type' => 'recurring', 'weekday' => $date->dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('16:00', '17:30')))->toBeFalse();
});

it('treats the half-open shift end boundary correctly', function (): void {
    $staff = availStaff();
    $date = businessDate();
    availRow($staff, ['type' => 'recurring', 'weekday' => $date->dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);

    // [09:00,17:00) — a proposal ending exactly at 17:00 is fully contained.
    expect(resolver()->isIntervalAvailable($staff, $date, interval('09:00', '17:00')))->toBeTrue();
});

it('supports split shifts and blocks the gap between them', function (): void {
    $staff = availStaff();
    $date = businessDate();
    $w = $date->dayOfWeek;
    availRow($staff, ['type' => 'recurring', 'weekday' => $w, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'available' => true]);
    availRow($staff, ['type' => 'recurring', 'weekday' => $w, 'start_time' => '14:00:00', 'end_time' => '18:00:00', 'available' => true]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('10:00', '11:00')))->toBeTrue()
        ->and(resolver()->isIntervalAvailable($staff, $date, interval('15:00', '16:00')))->toBeTrue()
        ->and(resolver()->isIntervalAvailable($staff, $date, interval('12:30', '13:30')))->toBeFalse();
});

it('blocks an interval overlapping a recurring break but allows immediately after it', function (): void {
    $staff = availStaff();
    $date = businessDate();
    $w = $date->dayOfWeek;
    availRow($staff, ['type' => 'recurring', 'weekday' => $w, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);
    availRow($staff, ['type' => 'recurring', 'weekday' => $w, 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'available' => false]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('12:30', '13:30')))->toBeFalse()
        ->and(resolver()->isIntervalAvailable($staff, $date, interval('14:00', '15:00')))->toBeTrue();
});

it('lets a date-specific unavailable exception override recurring availability', function (): void {
    $staff = availStaff();
    $date = businessDate();
    availRow($staff, ['type' => 'recurring', 'weekday' => $date->dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);
    availRow($staff, ['type' => 'exception', 'date' => $date->format('Y-m-d'), 'start_time' => '12:00:00', 'end_time' => '13:00:00', 'available' => false]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('12:30', '12:45')))->toBeFalse()
        ->and(resolver()->isIntervalAvailable($staff, $date, interval('09:30', '10:00')))->toBeTrue()
        ->and(resolver()->isIntervalAvailable($staff, $date, interval('13:00', '14:00')))->toBeTrue();
});

it('lets a date-specific available exception add a one-off working interval', function (): void {
    $staff = availStaff();
    $date = businessDate();
    // No recurring schedule on this weekday — offline by default, except the exception.
    availRow($staff, ['type' => 'exception', 'date' => $date->format('Y-m-d'), 'start_time' => '18:00:00', 'end_time' => '20:00:00', 'available' => true]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('18:30', '19:30')))->toBeTrue()
        ->and(resolver()->isIntervalAvailable($staff, $date, interval('17:00', '18:00')))->toBeFalse();
});

it('makes an overlapping exact-date unavailable win over an exact-date available', function (): void {
    $staff = availStaff();
    $date = businessDate();
    availRow($staff, ['type' => 'exception', 'date' => $date->format('Y-m-d'), 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'available' => true]);
    availRow($staff, ['type' => 'exception', 'date' => $date->format('Y-m-d'), 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'available' => false]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('10:15', '10:45')))->toBeFalse()
        ->and(resolver()->isIntervalAvailable($staff, $date, interval('09:15', '09:45')))->toBeTrue();
});

it('reports offline when there is no recurring schedule (ordinary day off)', function (): void {
    $staff = availStaff();
    $date = businessDate();

    expect(resolver()->isIntervalAvailable($staff, $date, interval('10:00', '11:00')))->toBeFalse()
        ->and(resolver()->currentState($staff, $date->setTime(10, 0)))->toBe(PersonnelAvailabilityState::Offline);
});

it('reflects emergency unavailability immediately in the resolver', function (): void {
    $staff = availStaff();
    $date = businessDate();
    availRow($staff, ['type' => 'recurring', 'weekday' => $date->dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('15:00', '16:00')))->toBeTrue();

    availRow($staff, ['type' => 'exception', 'date' => $date->format('Y-m-d'), 'start_time' => '14:00:00', 'end_time' => '17:00:00', 'available' => false]);

    expect(resolver()->isIntervalAvailable($staff, $date, interval('15:00', '16:00')))->toBeFalse();
});

it('derives current state available/on_break/unavailable/offline/suspended', function (): void {
    $staff = availStaff();
    $date = businessDate();
    $w = $date->dayOfWeek;
    availRow($staff, ['type' => 'recurring', 'weekday' => $w, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);
    availRow($staff, ['type' => 'recurring', 'weekday' => $w, 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'available' => false]);
    availRow($staff, ['type' => 'exception', 'date' => $date->format('Y-m-d'), 'start_time' => '15:00:00', 'end_time' => '16:00:00', 'available' => false]);

    expect(resolver()->currentState($staff, $date->setTime(10, 0)))->toBe(PersonnelAvailabilityState::Available)
        ->and(resolver()->currentState($staff, $date->setTime(13, 30)))->toBe(PersonnelAvailabilityState::OnBreak)
        ->and(resolver()->currentState($staff, $date->setTime(15, 30)))->toBe(PersonnelAvailabilityState::Unavailable)
        ->and(resolver()->currentState($staff, $date->setTime(20, 0)))->toBe(PersonnelAvailabilityState::Offline);
});

it('reports suspended from staff lifecycle regardless of schedule', function (): void {
    $staff = availStaff();
    $staff->update(['is_active' => false]);
    $date = businessDate();
    availRow($staff, ['type' => 'recurring', 'weekday' => $date->dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);

    expect(resolver()->currentState($staff, $date->setTime(10, 0)))->toBe(PersonnelAvailabilityState::Suspended);
});

it('resolves weekday and date in Africa/Nairobi, not UTC', function (): void {
    $staff = availStaff();
    // 2026-07-06 21:30 UTC == 2026-07-07 00:30 Africa/Nairobi (next day, Tuesday).
    $utcInstant = CarbonImmutable::parse('2026-07-06 21:30:00', 'UTC');
    $nairobiNext = $utcInstant->setTimezone('Africa/Nairobi'); // 2026-07-07
    availRow($staff, ['type' => 'recurring', 'weekday' => $nairobiNext->dayOfWeek, 'start_time' => '00:00:00', 'end_time' => '01:00:00', 'available' => true]);

    // The recurring row is on the NAIROBI weekday of the instant; resolver must agree.
    expect(resolver()->currentState($staff, $utcInstant))->toBe(PersonnelAvailabilityState::Available);
});

it('rejects a cross-midnight proposal at the value-object boundary', function (): void {
    expect(fn () => TimeInterval::fromStrings('23:00', '01:00'))->toThrow(InvalidArgumentException::class);
});
