<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('catalogue', 'eligibility');

/*
 | Personnel-service eligibility (Plan §19.3, §39; Phase 15A). HR owns it within
 | its branch; Branch Manager cannot mutate it; cross-branch is impossible; a
 | duplicate active pair is rejected; mutations are audited.
 */

function hrServiceAndStaff(): array
{
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    $service = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);
    $staff = StaffProfile::factory()->create(['merchant_id' => $merchant->id, 'primary_branch_id' => $branch->id]);

    return [$hr, $merchant, $branch, $service, $staff];
}

it('lets HR assign and revoke eligibility within its branch', function (): void {
    [$hr, $merchant, , $service, $staff] = hrServiceAndStaff();

    $this->actingAs($hr, 'sanctum')->postJson("/api/v1/services/{$service->ulid}/eligibility", [
        'staff_profile_id' => $staff->ulid,
    ])->assertCreated()->assertJsonPath('data.active', true);

    $this->actingAs($hr, 'sanctum')->getJson("/api/v1/services/{$service->ulid}/eligibility")
        ->assertOk()->assertJsonPath('data.0.staff_profile_id', $staff->ulid);

    $this->actingAs($hr, 'sanctum')->deleteJson("/api/v1/services/{$service->ulid}/eligibility/{$staff->ulid}")
        ->assertOk()->assertJsonPath('data.active', false);

    expect(AuditLog::query()->where('action', 'personnel_eligibility.assigned')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'personnel_eligibility.revoked')->exists())->toBeTrue();
});

it('rejects a duplicate active eligibility with a deterministic conflict', function (): void {
    [$hr, , , $service, $staff] = hrServiceAndStaff();

    $this->actingAs($hr, 'sanctum')->postJson("/api/v1/services/{$service->ulid}/eligibility", ['staff_profile_id' => $staff->ulid])
        ->assertCreated();

    $this->actingAs($hr, 'sanctum')->postJson("/api/v1/services/{$service->ulid}/eligibility", ['staff_profile_id' => $staff->ulid])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'eligibility_exists');
});

it('reactivates a revoked eligibility instead of duplicating the row', function (): void {
    [$hr, , , $service, $staff] = hrServiceAndStaff();

    $this->actingAs($hr, 'sanctum')->postJson("/api/v1/services/{$service->ulid}/eligibility", ['staff_profile_id' => $staff->ulid])->assertCreated();
    $this->actingAs($hr, 'sanctum')->deleteJson("/api/v1/services/{$service->ulid}/eligibility/{$staff->ulid}")->assertOk();
    $this->actingAs($hr, 'sanctum')->postJson("/api/v1/services/{$service->ulid}/eligibility", ['staff_profile_id' => $staff->ulid])
        ->assertCreated()->assertJsonPath('data.active', true);

    expect(ServicePersonnelEligibility::query()
        ->where('service_id', $service->id)->where('staff_profile_id', $staff->id)->count())->toBe(1);
});

it('forbids a Branch Manager from mutating eligibility', function (): void {
    [, $merchant, $branch, $service, $staff] = hrServiceAndStaff();
    [$bm] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/services/{$service->ulid}/eligibility", ['staff_profile_id' => $staff->ulid])
        ->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
});

it('rejects assigning personnel from a different branch', function (): void {
    [$hr, $merchant, , $service] = hrServiceAndStaff();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    // HR must be assigned to the other branch too, to even resolve the staff there.
    $otherStaff = StaffProfile::factory()->create(['merchant_id' => $merchant->id, 'primary_branch_id' => $otherBranch->id]);

    // HR (scoped to the service's branch) cannot resolve a staff profile in another
    // branch — the BranchScope 404s it before any same-branch check.
    $this->actingAs($hr, 'sanctum')->postJson("/api/v1/services/{$service->ulid}/eligibility", ['staff_profile_id' => $otherStaff->ulid])
        ->assertStatus(404);
});

it('cannot reach a service in another branch', function (): void {
    [$hr, $merchant] = hrServiceAndStaff();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $otherService = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $otherBranch->id]);
    $otherStaff = StaffProfile::factory()->create(['merchant_id' => $merchant->id, 'primary_branch_id' => $otherBranch->id]);

    $this->actingAs($hr, 'sanctum')->postJson("/api/v1/services/{$otherService->ulid}/eligibility", ['staff_profile_id' => $otherStaff->ulid])
        ->assertStatus(404);
});
