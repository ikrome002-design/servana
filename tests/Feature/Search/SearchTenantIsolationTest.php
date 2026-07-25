<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Scheduling\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('search', 'phase22', 'security', 'tenancy');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | Cross-tenant and cross-branch isolation (Plan §68, §8.2, §73).
 |
 | Every scenario places a MATCHING row on the other side of the boundary, so a
 | pass means the boundary held while the row was genuinely findable — not that
 | the query simply found nothing.
 |==============================================================================
 */

it('never returns another merchant client, even with an identical name', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();
    $ulids = searchResultUlids($response, 'client');

    expect($ulids)->toContain($scn['clientA']->ulid)
        ->and($ulids)->not->toContain($foreign['client']->ulid);
});

it('never returns another merchant appointment', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    $mine = Appointment::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'client_id' => $scn['clientA']->id,
        'service_id' => $scn['serviceA']->id,
    ]);
    $theirs = Appointment::factory()->create([
        'merchant_id' => $foreign['merchant']->id,
        'branch_id' => $foreign['branch']->id,
        'client_id' => $foreign['client']->id,
    ]);

    $response = search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['appointment']])->assertOk();
    $ulids = searchResultUlids($response, 'appointment');

    expect($ulids)->toContain($mine->ulid)
        ->and($ulids)->not->toContain($theirs->ulid);
});

it('never returns a row from a branch the caller is not assigned to', function (): void {
    $scn = searchScenario();

    // Both clients carry the same name; only branch A is in the actor's scope.
    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();
    $ulids = searchResultUlids($response, 'client');

    expect($ulids)->toContain($scn['clientA']->ulid)
        ->and($ulids)->not->toContain($scn['clientB']->ulid);
});

it('lets a merchant-wide role reach every branch of its own merchant but no other merchant', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    // Merchant Admin is the one merchant-WIDE membership (TenantContext::isBranchScoped() is false
    // and branchIds() is empty, meaning "all own branches"). `staff` is a type it can reach, through
    // `branches.manage_users_lifecycle`, so it is the right probe for branch EXPANSION.
    $admin = User::factory()->create();
    MerchantUser::factory()->create([
        'user_id' => $admin->id,
        'merchant_id' => $scn['merchant']->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    [, , $staffInA] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Personnel);
    [, , $staffInB] = branchStaff($scn['merchant'], $scn['branchB'], MerchantUserRole::Personnel);
    [, , $foreignStaff] = branchStaff($foreign['merchant'], $foreign['branch'], MerchantUserRole::Personnel);

    $staffInA->update(['display_name' => 'Njeri Kamau']);
    $staffInB->update(['display_name' => 'Njeri Kamau']);
    $foreignStaff->update(['display_name' => 'Njeri Kamau']);

    $response = search($admin, ['q' => 'Njeri', 'types' => ['staff']])->assertOk();
    $ulids = searchResultUlids($response, 'staff');

    expect($ulids)->toContain($staffInA->ulid)
        ->and($ulids)->toContain($staffInB->ulid)
        ->and($ulids)->not->toContain($foreignStaff->ulid);
});

it('confines a branch-scoped HR member to its own branch for the same staff search', function (): void {
    $scn = searchScenario();

    [$hr] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Hr);

    [, , $staffInA] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Personnel);
    [, , $staffInB] = branchStaff($scn['merchant'], $scn['branchB'], MerchantUserRole::Personnel);

    $staffInA->update(['display_name' => 'Njeri Kamau']);
    $staffInB->update(['display_name' => 'Njeri Kamau']);

    $response = search($hr, ['q' => 'Njeri', 'types' => ['staff']])->assertOk();
    $ulids = searchResultUlids($response, 'staff');

    expect($ulids)->toContain($staffInA->ulid)
        ->and($ulids)->not->toContain($staffInB->ulid);
});

it('treats a requested branch outside the caller scope as a narrowing filter, not a widening one', function (): void {
    $scn = searchScenario();

    // Asking for branch B (which the actor cannot reach) intersects to an EMPTY branch set, so the
    // result is empty — never branch B's row, and never an error that would confirm B exists.
    $response = search($scn['frontOffice'], [
        'q' => 'Amina',
        'branch_ulids' => [$scn['branchB']->ulid],
    ])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('treats a foreign-merchant branch ulid as an unknown filter without leaking its existence', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    $response = search($scn['frontOffice'], [
        'q' => 'Amina',
        'branch_ulids' => [$foreign['branch']->ulid],
    ])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('honours a legitimate own-branch narrowing filter', function (): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], [
        'q' => 'Amina',
        'branch_ulids' => [$scn['branchA']->ulid],
    ])->assertOk();

    expect(searchResultUlids($response, 'client'))->toContain($scn['clientA']->ulid);
});

it('stops returning a branch row the moment the branch assignment is removed', function (): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();

    BranchUserAssignment::query()
        ->where('merchant_user_id', $scn['foMembership']->id)
        ->delete();

    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();

    // No reachable branch means "match nothing", never "match everything".
    expect($response->json('data'))->toBe([]);
});
