<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'availability-api');

/*
 | Personnel availability API (Plan §80 Phase 15B). HR owns mutation
 | (`personnel.availability.manage`) within its branch; Branch Manager reads only;
 | atomic replacement; redacted audit. Backend is the authorization boundary.
 */

/** @return array{0: \App\Models\User, 1: \App\Domain\Merchants\Models\Merchant, 2: MerchantBranch, 3: \App\Domain\Hr\Models\StaffProfile} */
function hrAndPersonnel(): array
{
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hrUser] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [, , $staff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    return [$hrUser, $merchant, $branch, $staff];
}

function schedulePayload(array $overrides = []): array
{
    return array_merge([
        'recurring' => [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00', 'available' => true],
            ['weekday' => 1, 'start_time' => '14:00', 'end_time' => '17:00', 'available' => true],
            ['weekday' => 1, 'start_time' => '15:00', 'end_time' => '15:30', 'available' => false],
        ],
        'exceptions' => [
            ['date' => '2026-07-10', 'start_time' => '09:00', 'end_time' => '12:00', 'available' => false],
        ],
        'change_reason' => 'Initial weekly schedule',
    ], $overrides);
}

it('lets HR replace and read a schedule, returning only safe fields', function (): void {
    [$hr, , , $staff] = hrAndPersonnel();

    $this->actingAs($hr, 'sanctum')
        ->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())
        ->assertOk()
        ->assertJsonPath('data.staff.id', $staff->ulid)
        ->assertJsonPath('data.timezone', 'Africa/Nairobi')
        ->assertJsonPath('data.can.update', true)
        ->assertJsonCount(3, 'data.recurring')
        ->assertJsonCount(1, 'data.exceptions')
        ->assertJsonMissingPath('data.change_reason')
        ->assertJsonMissing(['phone_index' => true]);

    expect(PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->count())->toBe(4);

    $this->actingAs($hr, 'sanctum')
        ->getJson("/api/v1/staff/{$staff->ulid}/availability")
        ->assertOk()
        ->assertJsonCount(3, 'data.recurring')
        ->assertJsonCount(1, 'data.exceptions')
        ->assertJsonPath('data.recurring.0.weekday', 1);
});

it('writes exactly one coherent audit event per replacement, not one per row', function (): void {
    [$hr, $merchant, , $staff] = hrAndPersonnel();

    $this->actingAs($hr, 'sanctum')
        ->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())
        ->assertOk();

    $events = AuditLog::query()->where('action', 'personnel_availability.updated')->get();
    expect($events)->toHaveCount(1);

    $context = $events->first()->context;
    expect($context['staff_profile_id'])->toBe($staff->ulid)
        ->and($context['recurring_count'])->toBe(3)
        ->and($context['exception_count'])->toBe(1)
        ->and($context)->toHaveKey('change_reason');
});

it('lets HR issue emergency unavailability and audits it', function (): void {
    [$hr, , , $staff] = hrAndPersonnel();
    $this->actingAs($hr, 'sanctum')->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())->assertOk();

    $this->actingAs($hr, 'sanctum')
        ->postJson("/api/v1/staff/{$staff->ulid}/availability/emergency-unavailable", [
            'date' => '2026-07-13',
            'start_time' => '14:00',
            'end_time' => '17:00',
            'change_reason' => 'Family emergency',
        ])
        ->assertOk();

    expect(PersonnelAvailability::query()->where('staff_profile_id', $staff->id)
        ->where('type', 'exception')->where('date', '2026-07-13')->where('available', false)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'personnel_availability.emergency_unavailable')->count())->toBe(1);
});

it('requires a change reason for emergency unavailability', function (): void {
    [$hr, , , $staff] = hrAndPersonnel();

    $this->actingAs($hr, 'sanctum')
        ->postJson("/api/v1/staff/{$staff->ulid}/availability/emergency-unavailable", [
            'date' => '2026-07-13', 'start_time' => '14:00', 'end_time' => '17:00',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.change_reason.0', fn ($m) => is_string($m));
});

it('redacts embedded contact data from the audited change reason', function (): void {
    [$hr, , , $staff] = hrAndPersonnel();

    $this->actingAs($hr, 'sanctum')
        ->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload([
            'change_reason' => 'Call client +254712345678 or jane@example.com',
        ]))
        ->assertOk();

    $reason = AuditLog::query()->where('action', 'personnel_availability.updated')->first()->context['change_reason'];
    expect($reason)->not->toContain('+254712345678')
        ->and($reason)->not->toContain('jane@example.com')
        ->and($reason)->toContain('[redacted]');
});

it('lets a Branch Manager read but never mutate availability', function (): void {
    [$hr, $merchant, $branch, $staff] = hrAndPersonnel();
    $this->actingAs($hr, 'sanctum')->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())->assertOk();

    [$bm] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($bm, 'sanctum')
        ->getJson("/api/v1/staff/{$staff->ulid}/availability")
        ->assertOk()
        ->assertJsonPath('data.can.update', false)
        ->assertJsonCount(3, 'data.recurring');

    $this->actingAs($bm, 'sanctum')
        ->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())
        ->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');

    $this->actingAs($bm, 'sanctum')
        ->postJson("/api/v1/staff/{$staff->ulid}/availability/emergency-unavailable", [
            'date' => '2026-07-13', 'start_time' => '14:00', 'end_time' => '17:00', 'change_reason' => 'x',
        ])
        ->assertStatus(403);
});

it('forbids non-HR operational roles from mutating availability', function (string $role): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$actor] = branchStaff($merchant, $branch, MerchantUserRole::from($role));
    [, , $staff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $this->actingAs($actor, 'sanctum')
        ->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())
        ->assertStatus(403);
})->with(['front_office', 'finance', 'personnel', 'audit']);

it('forbids the Merchant Admin from mutating availability', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $staff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())
        ->assertStatus(403);
});

it('404s a foreign-tenant staff member without leaking existence', function (): void {
    [$hr] = hrAndPersonnel();
    [, $otherMerchant] = activeAdmin();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $otherMerchant->id]);
    [, , $foreign] = branchStaff($otherMerchant, $otherBranch, MerchantUserRole::Personnel);

    $this->actingAs($hr, 'sanctum')
        ->getJson("/api/v1/staff/{$foreign->ulid}/availability")
        ->assertStatus(404);
});

it('denies a same-tenant out-of-branch staff member for branch-scoped HR (403 policy posture)', function (): void {
    // Established posture (BelongsToMerchant::resolveRouteBinding): cross-MERCHANT
    // is a 404 (no existence leak); same-tenant out-of-BRANCH resolves the binding
    // (BranchScope removed) and is denied by the policy as a 403 — not a 404.
    [$hr, $merchant] = hrAndPersonnel();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $otherStaff] = branchStaff($merchant, $otherBranch, MerchantUserRole::Personnel);

    $this->actingAs($hr, 'sanctum')
        ->putJson("/api/v1/staff/{$otherStaff->ulid}/availability", schedulePayload())
        ->assertStatus(403);
});

it('replaces atomically — a valid payload commits all rows and removes the old set', function (): void {
    [$hr, , , $staff] = hrAndPersonnel();

    $this->actingAs($hr, 'sanctum')->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())->assertOk();
    expect(PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->count())->toBe(4);

    // A smaller schedule fully replaces the previous one (no mixed/leftover rows).
    $this->actingAs($hr, 'sanctum')->putJson("/api/v1/staff/{$staff->ulid}/availability", [
        'recurring' => [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00', 'available' => true]],
        'exceptions' => [],
        'change_reason' => 'Reduced hours',
    ])->assertOk();

    $rows = PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->weekday)->toBe(2);
});

it('rolls back the whole replacement on an invalid row, preserving the old schedule', function (): void {
    [$hr, , , $staff] = hrAndPersonnel();
    $this->actingAs($hr, 'sanctum')->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())->assertOk();
    $before = PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->count();

    // Two same-polarity overlapping recurring intervals on one weekday → rejected.
    $this->actingAs($hr, 'sanctum')->putJson("/api/v1/staff/{$staff->ulid}/availability", [
        'recurring' => [
            ['weekday' => 3, 'start_time' => '09:00', 'end_time' => '13:00', 'available' => true],
            ['weekday' => 3, 'start_time' => '12:00', 'end_time' => '15:00', 'available' => true],
        ],
        'exceptions' => [],
        'change_reason' => 'Bad payload',
    ])->assertStatus(422);

    expect(PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->count())->toBe($before)
        ->and(PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->where('weekday', 3)->exists())->toBeFalse();
});

it('is idempotent — the same normalized payload twice yields the same final schedule', function (): void {
    [$hr, , , $staff] = hrAndPersonnel();

    $this->actingAs($hr, 'sanctum')->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())->assertOk();
    $this->actingAs($hr, 'sanctum')->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload())->assertOk();

    expect(PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->count())->toBe(4);
});

it('ignores merchant_id and branch_id supplied in the request body', function (): void {
    [$hr, $merchant, $branch, $staff] = hrAndPersonnel();
    $foreignMerchantId = 999999;

    $this->actingAs($hr, 'sanctum')->putJson("/api/v1/staff/{$staff->ulid}/availability", schedulePayload([
        'merchant_id' => $foreignMerchantId,
        'branch_id' => $foreignMerchantId,
    ]))->assertOk();

    $row = PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->first();
    expect($row->merchant_id)->toBe($merchant->id)
        ->and($row->branch_id)->toBe($branch->id);
});

it('surfaces active eligible services in the read payload without contact data', function (): void {
    [$hr, $merchant, $branch, $staff] = hrAndPersonnel();
    $service = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id, 'name' => 'Haircut']);
    ServicePersonnelEligibility::query()->create([
        'merchant_id' => $merchant->id, 'branch_id' => $branch->id,
        'service_id' => $service->id, 'staff_profile_id' => $staff->id, 'active' => true,
    ]);

    $this->actingAs($hr, 'sanctum')
        ->getJson("/api/v1/staff/{$staff->ulid}/availability")
        ->assertOk()
        ->assertJsonPath('data.eligible_services.0.id', $service->ulid)
        ->assertJsonPath('data.eligible_services.0.name', 'Haircut');
});

it('exposes no platform/super-admin availability route', function (): void {
    $names = collect(app('router')->getRoutes())->map(fn ($r) => $r->getName())->filter();
    expect($names->filter(fn ($n) => str_contains((string) $n, 'availability'))->values()->all())
        ->toBe(['staff.availability.show', 'staff.availability.update', 'staff.availability.emergency-unavailable']);
});
