<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('search', 'phase22', 'security', 'contact-export');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | `served_client` — Personnel OWN-SCOPE search (D-22-06; Plan §64; ADR-010).
 |
 | Not indexed. It delegates to the Phase 21S ServedClientSelector, so "personally
 | served" means exactly what 21S already proved: at least one COMPLETED service
 | session performed by THIS staff profile, in the acting merchant and branch.
 |==============================================================================
 */

it('finds a client this personnel member personally served, by name', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiku']);

    $response = search($scn['user'], ['q' => 'Amina', 'types' => ['served_client']])->assertOk();

    expect(searchResultUlids($response, 'served_client'))->toContain($scn['client']->ulid);
});

it('never returns a client served by a DIFFERENT personnel member', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiku']);

    // A second Personnel member in the same branch, with their OWN served client of the same name.
    // A DIFFERENT phone is required: `clients_branch_active_phone_index_unique` makes two active
    // clients in one branch with the same number impossible (Phase 15A duplicate prevention).
    [$otherUser, , $otherStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    $otherClient = smsServedClient(
        $scn['merchant'],
        $scn['branch'],
        $otherStaff,
        $scn['service'],
        phone: '+254722555444',
    );
    $otherClient->update(['full_name' => 'Amina Wanjiku']);

    $mine = search($scn['user'], ['q' => 'Amina', 'types' => ['served_client']])->assertOk();
    $theirs = search($otherUser, ['q' => 'Amina', 'types' => ['served_client']])->assertOk();

    expect(searchResultUlids($mine, 'served_client'))
        ->toContain($scn['client']->ulid)
        ->not->toContain($otherClient->ulid);

    expect(searchResultUlids($theirs, 'served_client'))
        ->toContain($otherClient->ulid)
        ->not->toContain($scn['client']->ulid);
});

it('never returns a branch client the member never served', function (): void {
    $scn = smsScenario();

    // Same branch, same name, but NO completed session performed by this member.
    $stranger = smsServedClient(
        $scn['merchant'],
        $scn['branch'],
        $scn['staff'],
        $scn['service'],
        ConsentState::OptedIn,
        ServiceSessionStatus::Pending,
        '+254733222111',
    );
    $stranger->update(['full_name' => 'Amina Stranger']);

    $response = search($scn['user'], ['q' => 'Amina', 'types' => ['served_client']])->assertOk();

    // A pending session is provenance, not delivery (Plan §64).
    expect(searchResultUlids($response, 'served_client'))->not->toContain($stranger->ulid);
});

it('never returns another merchant served client', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiku']);

    $foreign = foreignSearchScenario();

    $response = search($scn['user'], ['q' => 'Amina', 'types' => ['served_client']])->assertOk();

    expect(searchResultUlids($response))->not->toContain($foreign['client']->ulid);
});

/*
 |--------------------------------------------------------------------------
 | NAME ONLY — no phone path exists here
 |--------------------------------------------------------------------------
 | A phone lookup on this surface would confirm whether a guessed number belongs
 | to a client this member served, which is precisely the extraction ADR-010
 | forbids.
 */

it('cannot find a served client by phone number, complete or partial', function (string $term): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiku']);

    $response = search($scn['user'], ['q' => $term, 'types' => ['served_client']])->assertOk();

    expect(searchResultUlids($response, 'served_client'))->not->toContain($scn['client']->ulid);
})->with([
    'complete e164' => '+254712345678',
    'kenyan local' => '0712345678',
    'last four' => '5678',
]);

it('returns no contact field for a served client, not even a masked one', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiku']);

    $response = search($scn['user'], ['q' => 'Amina', 'types' => ['served_client']])->assertOk();
    $body = $response->getContent();

    // The Phase 21S SCREEN shows `••• ••• 1234`; search shows nothing at all (decision D-22-03).
    expect($response->json('data.0.title'))->toBe('Amina Wanjiku')
        ->and($response->json('data.0.subtitle'))->toBeNull()
        ->and($body)->not->toContain('phone')
        ->and($body)->not->toContain('5678')
        ->and($body)->not->toContain('•••');
});

it('points a served-client result at the Personnel own-scope surface, never the client directory', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiku']);

    $response = search($scn['user'], ['q' => 'Amina', 'types' => ['served_client']])->assertOk();

    // A Personnel member holds no `client.view`, so the Front-Office client page is not a
    // legitimate destination for them.
    expect($response->json('data.0.route.name'))->toBe('personnel.sms')
        ->and($response->json('data.0.route.id'))->toBeNull();
});

it('withholds the served-client type from every non-personnel role', function (MerchantUserRole $role): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiku']);

    [$actor] = branchStaff($scn['merchant'], $scn['branch'], $role);

    $response = search($actor, ['q' => 'Amina', 'types' => ['served_client']])->assertOk();

    // `personnel.my_served_clients.view` is granted to PERSONNEL ONLY and is non-overridable.
    expect($response->json('data'))->toBe([]);
})->with([
    'front office' => MerchantUserRole::FrontOffice,
    'branch manager' => MerchantUserRole::BranchManager,
    'hr' => MerchantUserRole::Hr,
    'finance' => MerchantUserRole::Finance,
    'audit' => MerchantUserRole::Audit,
]);

it('returns nothing when the acting membership has no staff profile at all', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiku']);

    // A Personnel membership with NO staff profile (rather than deleting the seeded one, which is
    // referenced by its own service session and queue rows). Own-scope with no own staff profile is
    // "no results", never an unscoped query.
    $user = User::factory()->create();
    $membership = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $scn['merchant']->id,
        'role' => MerchantUserRole::Personnel,
    ]);
    BranchUserAssignment::factory()->create([
        'merchant_user_id' => $membership->id,
        'branch_id' => $scn['branch']->id,
    ]);

    $response = search($user, ['q' => 'Amina', 'types' => ['served_client']])->assertOk();

    expect($response->json('data'))->toBe([]);
});
