<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Enums\StaffEmploymentStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('front-office', 'local-uat-001', 'luat-d01');

/*
 | LOCAL-UAT-001, defect LUAT-D01 — Front Office operational selection (owner decision: "Narrow FO
 | endpoint"). The five Front Office pickers read the catalogue (`GET /services`,
 | `GET /services/{service}/eligibility`), which `service.view` (Branch Manager only) correctly
 | refuses. These reads give Front Office ONLY the choices its own workflow needs, gated per purpose by
 | the existing key of that workflow, without widening `service.view` or any other grant.
 */

const LUAT_D01_PERSONNEL_PURPOSES = ['walk_in', 'appointment', 'appointment_assign', 'appointment_transfer', 'queue_assign', 'queue_transfer'];

it('bounds selection collections using the standard pagination contract', function (): void {
    $scn = queueScenario();
    Service::factory()->count(25)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'category_id' => $scn['service']->category_id,
    ]);

    $base = '/api/v1/front-office/service-options?purpose=walk_in';
    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson($base)
        ->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 26);
    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson($base.'&page=2')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);

    foreach ([$base, "/api/v1/front-office/services/{$scn['service']->ulid}/personnel-options?purpose=walk_in"] as $url) {
        $this->actingAs($scn['frontOffice'], 'sanctum')->getJson($url.'&per_page=101')->assertStatus(422);
        $this->actingAs($scn['frontOffice'], 'sanctum')->getJson($url.'&page=0')->assertStatus(422);
        $this->actingAs($scn['frontOffice'], 'sanctum')->getJson($url.'&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1);
    }
});

it('lists only the acting branch active services, with picker fields only', function (): void {
    $scn = queueScenario();
    $archived = Service::factory()->archived()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    $otherBranchService = Service::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $otherBranch->id]);
    $foreign = Service::factory()->create();

    foreach (['walk_in', 'appointment'] as $purpose) {
        $response = $this->actingAs($scn['frontOffice'], 'sanctum')
            ->getJson("/api/v1/front-office/service-options?purpose={$purpose}")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$scn['service']->ulid])
            ->and($ids)->not->toContain($archived->ulid)
            ->and($ids)->not->toContain($otherBranchService->ulid)
            ->and($ids)->not->toContain($foreign->ulid)
            ->and(array_keys($response->json('data.0')))->toBe(['id', 'name', 'duration_minutes']);
    }
});

it('lists active, employed, eligible personnel for every personnel purpose, with picker fields only', function (): void {
    $scn = queueScenario();

    foreach (LUAT_D01_PERSONNEL_PURPOSES as $purpose) {
        $response = $this->actingAs($scn['frontOffice'], 'sanctum')
            ->getJson("/api/v1/front-office/services/{$scn['service']->ulid}/personnel-options?purpose={$purpose}")
            ->assertOk();

        expect(collect($response->json('data'))->pluck('staff_profile_id')->sort()->values()->all())
            ->toBe(collect([$scn['staff']->ulid, $scn['staff2']->ulid])->sort()->values()->all())
            ->and(array_keys($response->json('data.0')))->toBe(['staff_profile_id', 'staff_name', 'active']);
    }
});

it('never offers inactive eligibility, inactive staff or staff no longer employed', function (): void {
    $scn = queueScenario();
    [, , $inactiveEligibility] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    [, , $inactiveStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    [, , $terminated] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);

    foreach ([$inactiveEligibility, $inactiveStaff, $terminated] as $member) {
        ServicePersonnelEligibility::query()->create([
            'merchant_id' => $scn['merchant']->id,
            'branch_id' => $scn['branch']->id,
            'service_id' => $scn['service']->id,
            'staff_profile_id' => $member->id,
            'active' => $member->is($inactiveEligibility) ? false : true,
        ]);
    }
    $inactiveStaff->forceFill(['is_active' => false])->save();
    $terminated->forceFill(['employment_status' => StaffEmploymentStatus::Terminated])->save();

    $ids = collect($this->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson("/api/v1/front-office/services/{$scn['service']->ulid}/personnel-options?purpose=walk_in")
        ->assertOk()->json('data'))->pluck('staff_profile_id')->all();

    expect($ids)->not->toContain($inactiveEligibility->ulid)
        ->and($ids)->not->toContain($inactiveStaff->ulid)
        ->and($ids)->not->toContain($terminated->ulid)
        ->and($ids)->toHaveCount(2);
});

it('binds each purpose to its own workflow permission (one key never opens another workflow)', function (): void {
    $scn = queueScenario();
    $this->seed(PermissionSeeder::class);
    $membership = MerchantUser::query()->where('user_id', $scn['frontOffice']->id)->firstOrFail();
    MerchantUserPermissionOverride::query()->create([
        'merchant_user_id' => $membership->id,
        'merchant_id' => $membership->merchant_id,
        'permission_id' => Permission::query()->where('key', 'queue.transfer')->value('id'),
        'effect' => 'deny',
    ]);

    $base = "/api/v1/front-office/services/{$scn['service']->ulid}/personnel-options?purpose=";
    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson($base.'queue_transfer')->assertForbidden();
    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson($base.'queue_assign')->assertOk();
    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson($base.'walk_in')->assertOk();
});

it('refuses an unknown or missing purpose instead of falling back to an unscoped list', function (): void {
    $scn = queueScenario();

    foreach (['', '?purpose=catalogue', '?purpose=service.view', '?purpose=queue_transfer'] as $query) {
        $this->actingAs($scn['frontOffice'], 'sanctum')
            ->getJson('/api/v1/front-office/service-options'.$query)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }
    $this->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson("/api/v1/front-office/services/{$scn['service']->ulid}/personnel-options")
        ->assertStatus(422);
});

it('gives no selection to roles that do not run these Front Office workflows', function (): void {
    $scn = queueScenario();
    [$hr] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Hr);
    [$finance] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Finance);
    [$audit] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Audit);

    // The Branch Manager HOLDS service.view: the catalogue key must not open the Front Office selector.
    foreach ([$scn['branchManager'], $scn['staffUser'], $hr, $finance, $audit] as $user) {
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/front-office/service-options?purpose=walk_in')->assertForbidden();
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/front-office/services/{$scn['service']->ulid}/personnel-options?purpose=queue_assign")
            ->assertForbidden();
    }
});

it('requires an authenticated session', function (): void {
    $scn = queueScenario();

    $this->getJson('/api/v1/front-office/service-options?purpose=walk_in')->assertUnauthorized();
    $this->getJson("/api/v1/front-office/services/{$scn['service']->ulid}/personnel-options?purpose=walk_in")->assertUnauthorized();
});

it('hides foreign-merchant, other-branch and archived services (404, never their personnel)', function (): void {
    $scn = queueScenario();
    $foreign = Service::factory()->create(); // its own merchant and branch
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    $otherBranchService = Service::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $otherBranch->id]);
    $archived = Service::factory()->archived()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);

    foreach ([$foreign, $otherBranchService, $archived] as $service) {
        $this->actingAs($scn['frontOffice'], 'sanctum')
            ->getJson("/api/v1/front-office/services/{$service->ulid}/personnel-options?purpose=walk_in")
            ->assertNotFound();
    }
});

it('leaves the catalogue reads Branch-Manager-only (service.view is unchanged)', function (): void {
    $scn = queueScenario();

    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson('/api/v1/services?status=active')->assertForbidden();
    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson("/api/v1/services/{$scn['service']->ulid}")->assertForbidden();
    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson("/api/v1/services/{$scn['service']->ulid}/eligibility")->assertForbidden();
    $this->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson('/api/v1/services', ['name' => 'Not allowed', 'price_minor' => 100, 'duration_minutes' => 30])
        ->assertForbidden();
    $this->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/services/{$scn['service']->ulid}/eligibility", ['staff_profile_id' => $scn['staff']->ulid])
        ->assertForbidden();

    $this->actingAs($scn['branchManager'], 'sanctum')->getJson('/api/v1/services?status=active')->assertOk();
});

it('is read-only: a selection writes nothing', function (): void {
    $scn = queueScenario();
    $tables = ['services', 'service_personnel_eligibility', 'queue_entries', 'appointments', 'audit_logs'];
    $before = collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()]);

    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson('/api/v1/front-office/service-options?purpose=appointment')->assertOk();
    $this->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson("/api/v1/front-office/services/{$scn['service']->ulid}/personnel-options?purpose=appointment")->assertOk();

    expect(collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()]))->toEqual($before);
});

it('reserves nothing: a choice that becomes ineligible is refused again on submission', function (): void {
    $scn = queueScenario();

    $offered = $this->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson("/api/v1/front-office/services/{$scn['service']->ulid}/personnel-options?purpose=walk_in")
        ->assertOk()->json('data');
    expect(collect($offered)->pluck('staff_profile_id'))->toContain($scn['staff']->ulid);

    ServicePersonnelEligibility::query()->where('staff_profile_id', $scn['staff']->id)->update(['active' => false]);

    createWalkIn($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid])->assertStatus(422);
    expect(QueueEntry::query()->count())->toBe(0);
});

it('does not leak another tenant when the caller belongs to two merchants', function (): void {
    $scn = queueScenario();
    $other = queueScenario();

    // The other merchant's service ULID is not resolvable from the first merchant's context.
    $this->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson("/api/v1/front-office/services/{$other['service']->ulid}/personnel-options?purpose=walk_in")
        ->assertNotFound();

    $ids = collect($this->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson('/api/v1/front-office/service-options?purpose=walk_in')->assertOk()->json('data'))->pluck('id');
    expect($ids)->not->toContain($other['service']->ulid);
    expect(User::query()->count())->toBeGreaterThan(0);
});
