<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Services\PersonnelSchedulingValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'scheduling-validator');

/*
 | THE reusable eligibility + availability gate (Plan §80 Phase 15B). Built and
 | DIRECTLY tested here — no appointment/queue/session record is required to run
 | it. Phase 16A must invoke it on every appointment assign/transfer/reschedule.
 */

/**
 * A fully valid scheduling scenario: active service, active eligible personnel
 * with an active branch assignment, and a recurring available window covering the
 * proposed interval.
 *
 * @return array{0: Merchant, 1: MerchantBranch, 2: Service, 3: \App\Domain\Hr\Models\StaffProfile, 4: CarbonImmutable, 5: CarbonImmutable}
 */
function validScenario(): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $staff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);
    $service = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    ServicePersonnelEligibility::query()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'staff_profile_id' => $staff->id,
        'active' => true,
    ]);

    $date = CarbonImmutable::parse('2026-07-06', 'Africa/Nairobi');
    PersonnelAvailability::query()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'staff_profile_id' => $staff->id,
        'type' => 'recurring',
        'weekday' => $date->dayOfWeek,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'available' => true,
    ]);

    return [$merchant, $branch, $service, $staff, $date->setTime(10, 0), $date->setTime(11, 0)];
}

function schedulingValidator(): PersonnelSchedulingValidator
{
    return app(PersonnelSchedulingValidator::class);
}

it('passes for an active service, active eligible personnel, correct branch, and available interval', function (): void {
    [$m, $b, $service, $staff, $start, $end] = validScenario();

    $decision = schedulingValidator()->validate($m, $b, $service, $staff, $start, $end);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->code)->toBeNull();
});

it('fails with personnel_not_eligible when no eligibility exists', function (): void {
    [$m, $b, $service, $staff, $start, $end] = validScenario();
    ServicePersonnelEligibility::query()->where('staff_profile_id', $staff->id)->delete();

    expect(schedulingValidator()->validate($m, $b, $service, $staff, $start, $end)->code)
        ->toBe(PersonnelSchedulingValidator::CODE_NOT_ELIGIBLE);
});

it('fails with personnel_not_eligible when eligibility is inactive', function (): void {
    [$m, $b, $service, $staff, $start, $end] = validScenario();
    ServicePersonnelEligibility::query()->where('staff_profile_id', $staff->id)->update(['active' => false]);

    expect(schedulingValidator()->validate($m, $b, $service, $staff, $start, $end)->code)
        ->toBe(PersonnelSchedulingValidator::CODE_NOT_ELIGIBLE);
});

it('fails with personnel_unavailable when the interval is outside availability', function (): void {
    [$m, $b, $service, $staff, , ] = validScenario();
    $date = CarbonImmutable::parse('2026-07-06', 'Africa/Nairobi');

    expect(schedulingValidator()->validate($m, $b, $service, $staff, $date->setTime(18, 0), $date->setTime(19, 0))->code)
        ->toBe(PersonnelSchedulingValidator::CODE_UNAVAILABLE);
});

it('fails with personnel_inactive for inactive (suspended) staff', function (): void {
    [$m, $b, $service, $staff, $start, $end] = validScenario();
    $staff->update(['is_active' => false]);

    expect(schedulingValidator()->validate($m, $b, $service, $staff, $start, $end)->code)
        ->toBe(PersonnelSchedulingValidator::CODE_PERSONNEL_INACTIVE);
});

it('fails with service_inactive when the service is archived', function (): void {
    [$m, $b, $service, $staff, $start, $end] = validScenario();
    $service->update(['status' => 'archived']);

    expect(schedulingValidator()->validate($m, $b, $service, $staff->refresh(), $start, $end)->code)
        ->toBe(PersonnelSchedulingValidator::CODE_SERVICE_INACTIVE);
});

it('fails neutrally (no cross-tenant existence leak) for a wrong-tenant pairing', function (): void {
    [$m, $b, $service, , $start, $end] = validScenario();
    // A staff member from an entirely different merchant.
    $otherMerchant = Merchant::factory()->active()->create();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $otherMerchant->id]);
    [, , $otherStaff] = branchStaff($otherMerchant, $otherBranch, MerchantUserRole::Personnel);

    $decision = schedulingValidator()->validate($m, $b, $service, $otherStaff, $start, $end);

    expect($decision->code)->toBe(PersonnelSchedulingValidator::CODE_NOT_ELIGIBLE)
        ->and($decision->message)->not->toContain((string) $otherMerchant->id)
        ->and($decision->message)->not->toContain((string) $otherStaff->id);
});

it('fails with personnel_wrong_branch for a same-tenant, different-branch staff', function (): void {
    [$m, $b, $service, , $start, $end] = validScenario();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $m->id]);
    [, , $otherStaff] = branchStaff($m, $otherBranch, MerchantUserRole::Personnel);

    expect(schedulingValidator()->validate($m, $b, $service, $otherStaff, $start, $end)->code)
        ->toBe(PersonnelSchedulingValidator::CODE_WRONG_BRANCH);
});

it('fails with invalid_schedule_window for a cross-business-date interval', function (): void {
    [$m, $b, $service, $staff] = validScenario();
    $start = CarbonImmutable::parse('2026-07-06 23:00', 'Africa/Nairobi');
    $end = CarbonImmutable::parse('2026-07-07 01:00', 'Africa/Nairobi');

    expect(schedulingValidator()->validate($m, $b, $service, $staff, $start, $end)->code)
        ->toBe(PersonnelSchedulingValidator::CODE_INVALID_WINDOW);
});

it('throws the canonical envelope exception from ensure() on a denial', function (): void {
    [$m, $b, $service, $staff] = validScenario();
    $date = CarbonImmutable::parse('2026-07-06', 'Africa/Nairobi');

    schedulingValidator()->ensure($m, $b, $service, $staff, $date->setTime(18, 0), $date->setTime(19, 0));
})->throws(App\Domain\Scheduling\Exceptions\SchedulingValidationException::class);

it('runs without any appointment, queue, or session record', function (): void {
    [$m, $b, $service, $staff, $start, $end] = validScenario();

    // No scheduling aggregate exists yet — the validator is fully exercised anyway.
    expect(class_exists('App\\Domain\\Scheduling\\Models\\Appointment'))->toBeFalse()
        ->and(schedulingValidator()->validate($m, $b, $service, $staff, $start, $end)->allowed)->toBeTrue();
});
