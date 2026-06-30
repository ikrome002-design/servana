<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'queue', 'queue-assignment');

it('rejects a manual assignment to an ineligible personnel member (422)', function (): void {
    $scn = queueScenario();
    ServicePersonnelEligibility::query()
        ->where('service_id', $scn['service']->id)->where('staff_profile_id', $scn['staff']->id)->delete();

    createWalkIn($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid])
        ->assertStatus(422)->assertJsonPath('error.code', 'personnel_not_eligible');
});

it('returns 404 for a manual target in another branch', function (): void {
    $scn = queueScenario();
    [, , $otherBranchStaff] = branchStaff($scn['merchant'], MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]), MerchantUserRole::Personnel);

    createWalkIn($scn, ['assignment_mode' => 'manual', 'personnel' => $otherBranchStaff->ulid])
        ->assertNotFound();
});

it('leaves a preferred request waiting when the preferred member is unavailable', function (): void {
    $scn = queueScenario();
    PersonnelAvailability::query()->where('staff_profile_id', $scn['staff']->id)->delete();

    $response = createWalkIn($scn, ['assignment_mode' => 'preferred_personnel', 'preferred_personnel' => $scn['staff']->ulid])
        ->assertStatus(201);

    $response->assertJsonPath('data.status', 'waiting')
        ->assertJsonPath('data.preferred_personnel.id', $scn['staff']->ulid)
        ->assertJsonPath('data.assigned_personnel', null);
});

it('requires a reason to override a preferred request to another person', function (): void {
    $scn = queueScenario();
    // Preferred = staff, but staff is unavailable so the entry starts waiting with the preference recorded.
    PersonnelAvailability::query()->where('staff_profile_id', $scn['staff']->id)->delete();
    $ulid = createWalkIn($scn, ['assignment_mode' => 'preferred_personnel', 'preferred_personnel' => $scn['staff']->ulid])->json('data.id');

    // Override to staff2 (available) without a reason → 422 reason_required.
    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/assign", [
        'assignment_mode' => 'manual', 'personnel' => $scn['staff2']->ulid,
    ])->assertStatus(422)->assertJsonPath('error.code', 'reason_required');

    // With a reason → assigned to staff2.
    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/assign", [
        'assignment_mode' => 'manual', 'personnel' => $scn['staff2']->ulid, 'reason' => 'Preferred unavailable today',
    ])->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.assigned_personnel.id', $scn['staff2']->ulid);
});

it('keeps a next-available entry waiting when nobody is eligible/available', function (): void {
    $scn = queueScenario();
    ServicePersonnelEligibility::query()->where('service_id', $scn['service']->id)->delete();

    createWalkIn($scn)->assertStatus(201)->assertJsonPath('data.status', QueueEntryStatus::Waiting->value);
});
