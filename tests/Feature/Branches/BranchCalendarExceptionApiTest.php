<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Branches\Models\BranchCalendarException;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Exceptions\AppointmentBranchScheduleException;
use App\Domain\Scheduling\Services\AppointmentBranchScheduleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('branches', 'branch-calendar', 'rem-scr-002');

/*
 |==============================================================================
 | REM-SCR-002B — branch calendar exceptions (Plan §7.2, §27.3 Branch Manager
 | "branch profile/calendar", Scope §3.3; §19.3:1465 branch.calendar.manage).
 |
 | The table, model and the runtime consumer (AppointmentBranchScheduleValidator) all shipped
 | long ago; the operator surface was missing, so a branch could never actually be closed for a
 | public holiday. These tests prove the surface AND that it drives the existing scheduling gate.
 |
 | File-local helpers carry unique names (a Pest file-scope function is GLOBAL; cf. PH23-TEST-001).
 */

/** Branch Manager + an assigned branch with normal Mon–Sun 09:00–17:00 hours. */
function bceScenario(): array
{
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    for ($weekday = 0; $weekday <= 6; $weekday++) {
        BranchOperatingHour::query()->create([
            'merchant_id' => $merchant->id,
            'branch_id' => $branch->id,
            'weekday' => $weekday,
            'opens_at' => '09:00:00',
            'closes_at' => '17:00:00',
            'is_closed' => false,
        ]);
    }

    return compact('admin', 'merchant', 'branch', 'manager');
}

function bceUrl(MerchantBranch $branch, ?string $date = null): string
{
    $base = "/api/v1/branches/{$branch->ulid}/calendar-exceptions";

    return $date === null ? $base : "{$base}/{$date}";
}

/** A future business date, so no test depends on the wall clock's position in the day. */
function bceDate(int $daysAhead = 30): string
{
    return CarbonImmutable::now('Africa/Nairobi')->addDays($daysAhead)->toDateString();
}

it('requires authentication on every calendar route', function (): void {
    $scn = bceScenario();
    $date = bceDate();

    test()->getJson(bceUrl($scn['branch']))->assertUnauthorized();
    test()->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'public_holiday'])->assertUnauthorized();
    test()->patchJson(bceUrl($scn['branch'], $date), ['reason' => 'x'])->assertUnauthorized();
    test()->deleteJson(bceUrl($scn['branch'], $date))->assertUnauthorized();
});

it('lets the Branch Manager create a full-day closure and read it back', function (): void {
    $scn = bceScenario();
    $date = bceDate();

    $body = test()->actingAs($scn['manager'], 'sanctum')
        ->postJson(bceUrl($scn['branch']), [
            'date' => $date,
            'type' => 'public_holiday',
            'reason' => 'Jamhuri Day',
        ])->assertCreated()->json();

    expect($body['data'])->toHaveKeys(['date', 'type', 'closes_branch', 'opens_at', 'closes_at', 'reason'])
        ->and($body['data']['date'])->toBe($date)
        ->and($body['data']['type'])->toBe('public_holiday')
        ->and($body['data']['closes_branch'])->toBeTrue()
        ->and($body['data']['opens_at'])->toBeNull();

    // No internal id, branch id, merchant id or actor id is ever exposed.
    $raw = json_encode($body);
    foreach (['branch_id', 'merchant_id', 'created_by', '"id"'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }

    test()->actingAs($scn['manager'], 'sanctum')->getJson(bceUrl($scn['branch']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.date', $date);
});

it('creates modified hours and requires a coherent window', function (): void {
    $scn = bceScenario();
    $date = bceDate();
    $actor = test()->actingAs($scn['manager'], 'sanctum');

    // Missing times → 422 (a modified-hours row with no window is treated as CLOSED by the
    // scheduling gate, which would silently contradict the operator).
    $actor->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'modified_hours'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['opens_at', 'closes_at']]]);

    // End before start → 422.
    $actor->postJson(bceUrl($scn['branch']), [
        'date' => $date, 'type' => 'modified_hours', 'opens_at' => '14:00', 'closes_at' => '10:00',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['closes_at']]]);

    $actor->postJson(bceUrl($scn['branch']), [
        'date' => $date, 'type' => 'modified_hours', 'opens_at' => '11:00', 'closes_at' => '15:00',
    ])->assertCreated()
        ->assertJsonPath('data.closes_branch', false)
        ->assertJsonPath('data.opens_at', '11:00:00');
});

it('refuses to attach opening hours to a closure type', function (): void {
    $scn = bceScenario();

    test()->actingAs($scn['manager'], 'sanctum')->postJson(bceUrl($scn['branch']), [
        'date' => bceDate(), 'type' => 'emergency_closure', 'opens_at' => '09:00', 'closes_at' => '12:00',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['type']]]);
});

it('allows exactly one exception per date and reports the conflict deterministically', function (): void {
    $scn = bceScenario();
    $date = bceDate();
    $actor = test()->actingAs($scn['manager'], 'sanctum');

    $actor->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'public_holiday'])->assertCreated();

    // A second exception on the SAME date — even of a different type, which the DB unique
    // constraint would permit — is rejected, because the scheduling gate resolves a date to ONE
    // exception and two rows would make its decision order-dependent.
    $actor->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'modified_hours', 'opens_at' => '10:00', 'closes_at' => '14:00'])
        ->assertStatus(422)->assertJsonPath('error.code', 'calendar_exception_exists');

    expect(BranchCalendarException::query()->where('branch_id', $scn['branch']->id)->count())->toBe(1);
});

it('validates the date and type', function (): void {
    $scn = bceScenario();
    $actor = test()->actingAs($scn['manager'], 'sanctum');

    $actor->postJson(bceUrl($scn['branch']), ['date' => '2026/13/40', 'type' => 'public_holiday'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['date']]]);
    $actor->postJson(bceUrl($scn['branch']), ['date' => bceDate(), 'type' => 'invented_type'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['type']]]);
    $actor->postJson(bceUrl($scn['branch']), ['date' => bceDate(), 'type' => 'public_holiday', 'reason' => str_repeat('a', 256)])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);
});

it('updates the window and the reason but never the date or type', function (): void {
    $scn = bceScenario();
    $date = bceDate();
    $actor = test()->actingAs($scn['manager'], 'sanctum');

    $actor->postJson(bceUrl($scn['branch']), [
        'date' => $date, 'type' => 'modified_hours', 'opens_at' => '11:00', 'closes_at' => '15:00',
    ])->assertCreated();

    $actor->patchJson(bceUrl($scn['branch'], $date), [
        'opens_at' => '12:00',
        'reason' => 'Staff training',
        // Not updatable — the (date, type) pair is the row's identity.
        'date' => bceDate(45),
        'type' => 'emergency_closure',
    ])->assertOk()
        ->assertJsonPath('data.opens_at', '12:00:00')
        ->assertJsonPath('data.reason', 'Staff training')
        ->assertJsonPath('data.date', $date)
        ->assertJsonPath('data.type', 'modified_hours');

    // The invalid window is still rejected on update.
    $actor->patchJson(bceUrl($scn['branch'], $date), ['closes_at' => '10:00'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['closes_at']]]);
});

it('removes an exception and 404s an unknown date', function (): void {
    $scn = bceScenario();
    $date = bceDate();
    $actor = test()->actingAs($scn['manager'], 'sanctum');

    $actor->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'special_closure', 'reason' => 'Renovation'])
        ->assertCreated();

    $actor->deleteJson(bceUrl($scn['branch'], $date))->assertNoContent();
    expect(BranchCalendarException::query()->where('branch_id', $scn['branch']->id)->count())->toBe(0);

    $actor->deleteJson(bceUrl($scn['branch'], bceDate(60)))->assertNotFound();
    $actor->patchJson(bceUrl($scn['branch'], bceDate(60)), ['reason' => 'x'])->assertNotFound();
});

it('emits exactly one typed audit event per create, update and removal', function (): void {
    $scn = bceScenario();
    $date = bceDate();
    $actor = test()->actingAs($scn['manager'], 'sanctum');

    $actor->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'public_holiday'])->assertCreated();
    expect(AuditLog::query()->where('action', AuditEvent::BranchCalendarExceptionSet->value)->count())->toBe(1);

    $actor->patchJson(bceUrl($scn['branch'], $date), ['reason' => 'Gazetted'])->assertOk();
    expect(AuditLog::query()->where('action', AuditEvent::BranchCalendarExceptionSet->value)->count())->toBe(2);

    $actor->deleteJson(bceUrl($scn['branch'], $date))->assertNoContent();

    $removed = AuditLog::query()->where('action', AuditEvent::BranchCalendarExceptionRemoved->value)->get();
    expect($removed)->toHaveCount(1);
    expect(json_encode($removed->first()->context))->toContain($date)->toContain('public_holiday');
});

it('bounds the calendar read and rejects an inverted or oversized range', function (): void {
    $scn = bceScenario();
    $actor = test()->actingAs($scn['manager'], 'sanctum');

    $actor->getJson(bceUrl($scn['branch']).'?from=2026-08-01&to=2026-07-01')->assertStatus(422);
    $actor->getJson(bceUrl($scn['branch']).'?from=2026-01-01&to=2028-01-01')->assertStatus(422);
    $actor->getJson(bceUrl($scn['branch']).'?from=not-a-date')->assertStatus(422);

    $actor->getJson(bceUrl($scn['branch']).'?from=2026-08-01&to=2026-09-01')
        ->assertOk()->assertJsonPath('meta.from', '2026-08-01')->assertJsonPath('meta.to', '2026-09-01');
});

it('denies every role except the Branch Manager', function (): void {
    $scn = bceScenario();
    $date = bceDate();

    foreach ([
        MerchantUserRole::Hr,
        MerchantUserRole::Finance,
        MerchantUserRole::FrontOffice,
        MerchantUserRole::Personnel,
        MerchantUserRole::Audit,
    ] as $role) {
        [$user] = branchStaff($scn['merchant'], $scn['branch'], $role);

        test()->actingAs($user, 'sanctum')->getJson(bceUrl($scn['branch']))->assertForbidden();
        test()->actingAs($user, 'sanctum')
            ->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'public_holiday'])->assertForbidden();
    }

    // The Merchant Administrator owns branch LIFECYCLE, not the branch operating calendar
    // (Plan §10.2: it never configures branch operations). It holds no branch.calendar.manage.
    test()->actingAs($scn['admin'], 'sanctum')->getJson(bceUrl($scn['branch']))->assertForbidden();

    expect(BranchCalendarException::query()->count())->toBe(0);
});

it('404s a foreign-tenant branch and denies a same-tenant unassigned branch', function (): void {
    $scn = bceScenario();
    $other = bceScenario();
    $date = bceDate();

    // Foreign tenant → 404, no existence leak.
    test()->actingAs($scn['manager'], 'sanctum')->getJson(bceUrl($other['branch']))->assertNotFound();
    test()->actingAs($scn['manager'], 'sanctum')
        ->postJson(bceUrl($other['branch']), ['date' => $date, 'type' => 'public_holiday'])->assertNotFound();

    // Same tenant, branch the manager is NOT assigned to → documented 403 no_branch_scope.
    $sibling = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    test()->actingAs($scn['manager'], 'sanctum')->getJson(bceUrl($sibling))->assertForbidden();

    expect(BranchCalendarException::query()->count())->toBe(0);
});

it('never lets a caller-supplied branch or merchant id widen the server scope', function (): void {
    $scn = bceScenario();
    $other = bceScenario();

    test()->actingAs($scn['manager'], 'sanctum')->postJson(bceUrl($scn['branch']), [
        'date' => bceDate(),
        'type' => 'public_holiday',
        'branch_id' => $other['branch']->id,
        'merchant_id' => $other['merchant']->id,
    ])->assertCreated();

    $row = BranchCalendarException::query()->withoutGlobalScopes()->firstOrFail();
    expect($row->branch_id)->toBe($scn['branch']->id)
        ->and($row->merchant_id)->toBe($scn['merchant']->id);
});

it('blocks calendar mutation while billing is read-only but keeps the read available', function (): void {
    $scn = bceScenario();
    $date = bceDate();

    foreach ([MerchantBillingStatus::ReadOnlyGrace, MerchantBillingStatus::SuspendedBilling] as $status) {
        $scn['merchant']->forceFill(['billing_status' => $status->value])->save();

        test()->actingAs($scn['manager'], 'sanctum')->getJson(bceUrl($scn['branch']))->assertOk();
        test()->actingAs($scn['manager'], 'sanctum')
            ->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'public_holiday'])
            ->assertForbidden()->assertJsonPath('error.code', 'billing_read_only');
    }

    expect(BranchCalendarException::query()->count())->toBe(0);
});

// ── Runtime integration: the surface must drive the EXISTING scheduling gate ──────────

it('makes the scheduling gate close the branch once a closure exception exists', function (): void {
    $scn = bceScenario();
    $date = bceDate();
    $validator = app(AppointmentBranchScheduleValidator::class);
    $start = CarbonImmutable::parse("{$date} 10:00", 'Africa/Nairobi');
    $end = $start->addHour();

    // Inside normal hours before the exception → allowed.
    $validator->ensure($scn['branch']->fresh(), $start, $end);

    test()->actingAs($scn['manager'], 'sanctum')
        ->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'public_holiday'])->assertCreated();

    expect(fn () => $validator->ensure($scn['branch']->fresh(), $start, $end))
        ->toThrow(AppointmentBranchScheduleException::class);
});

it('makes the scheduling gate honour modified hours exactly', function (): void {
    $scn = bceScenario();
    $date = bceDate();
    $validator = app(AppointmentBranchScheduleValidator::class);

    test()->actingAs($scn['manager'], 'sanctum')->postJson(bceUrl($scn['branch']), [
        'date' => $date, 'type' => 'modified_hours', 'opens_at' => '12:00', 'closes_at' => '15:00',
    ])->assertCreated();

    $branch = $scn['branch']->fresh();

    // 10:00 is inside the NORMAL window but outside the modified one → now rejected.
    expect(fn () => $validator->ensure(
        $branch,
        CarbonImmutable::parse("{$date} 10:00", 'Africa/Nairobi'),
        CarbonImmutable::parse("{$date} 11:00", 'Africa/Nairobi'),
    ))->toThrow(AppointmentBranchScheduleException::class);

    // 13:00 is inside the modified window → allowed.
    $validator->ensure(
        $branch,
        CarbonImmutable::parse("{$date} 13:00", 'Africa/Nairobi'),
        CarbonImmutable::parse("{$date} 14:00", 'Africa/Nairobi'),
    );
});

it('stops blocking once the exception is removed', function (): void {
    $scn = bceScenario();
    $date = bceDate();
    $validator = app(AppointmentBranchScheduleValidator::class);
    $start = CarbonImmutable::parse("{$date} 10:00", 'Africa/Nairobi');
    $end = $start->addHour();

    test()->actingAs($scn['manager'], 'sanctum')
        ->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'special_closure', 'reason' => 'Deep clean'])
        ->assertCreated();
    expect(fn () => $validator->ensure($scn['branch']->fresh(), $start, $end))
        ->toThrow(AppointmentBranchScheduleException::class);

    test()->actingAs($scn['manager'], 'sanctum')->deleteJson(bceUrl($scn['branch'], $date))->assertNoContent();

    $validator->ensure($scn['branch']->fresh(), $start, $end); // no throw
});

it('confines a closure to its own branch and its own tenant', function (): void {
    $scn = bceScenario();
    $other = bceScenario();
    $sibling = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    foreach (range(0, 6) as $weekday) {
        BranchOperatingHour::query()->create([
            'merchant_id' => $scn['merchant']->id, 'branch_id' => $sibling->id, 'weekday' => $weekday,
            'opens_at' => '09:00:00', 'closes_at' => '17:00:00', 'is_closed' => false,
        ]);
    }

    $date = bceDate();
    $validator = app(AppointmentBranchScheduleValidator::class);
    $start = CarbonImmutable::parse("{$date} 10:00", 'Africa/Nairobi');
    $end = $start->addHour();

    test()->actingAs($scn['manager'], 'sanctum')
        ->postJson(bceUrl($scn['branch']), ['date' => $date, 'type' => 'public_holiday'])->assertCreated();

    // The closed branch throws; the sibling branch and the other tenant's branch do not.
    expect(fn () => $validator->ensure($scn['branch']->fresh(), $start, $end))
        ->toThrow(AppointmentBranchScheduleException::class);
    $validator->ensure($sibling->fresh(), $start, $end);
    $validator->ensure($other['branch']->fresh(), $start, $end);
});

it('resolves the exception on the Nairobi business date, not the UTC date', function (): void {
    // 22:30 UTC on day D is already 01:30 on day D+1 in Nairobi. A closure written for the
    // NAIROBI date must therefore bind at that instant — this is the same UTC/Nairobi divergence
    // that produced PH23-DET-001, pinned here so the calendar can never regress into UTC.
    $scn = bceScenario();
    $utcInstant = CarbonImmutable::parse('2026-11-10 22:30:00', 'UTC');
    $nairobiDate = $utcInstant->setTimezone('Africa/Nairobi')->toDateString(); // 2026-11-11
    expect($nairobiDate)->toBe('2026-11-11');

    test()->actingAs($scn['manager'], 'sanctum')
        ->postJson(bceUrl($scn['branch']), ['date' => $nairobiDate, 'type' => 'public_holiday'])->assertCreated();

    $validator = app(AppointmentBranchScheduleValidator::class);

    // 10:00 Nairobi on the CLOSED Nairobi date → blocked.
    expect(fn () => $validator->ensure(
        $scn['branch']->fresh(),
        CarbonImmutable::parse("{$nairobiDate} 10:00", 'Africa/Nairobi'),
        CarbonImmutable::parse("{$nairobiDate} 11:00", 'Africa/Nairobi'),
    ))->toThrow(AppointmentBranchScheduleException::class);

    // 10:00 Nairobi on the PREVIOUS Nairobi date → unaffected.
    $previous = $utcInstant->setTimezone('Africa/Nairobi')->subDay()->toDateString();
    $validator->ensure(
        $scn['branch']->fresh(),
        CarbonImmutable::parse("{$previous} 10:00", 'Africa/Nairobi'),
        CarbonImmutable::parse("{$previous} 11:00", 'Africa/Nairobi'),
    );
});
