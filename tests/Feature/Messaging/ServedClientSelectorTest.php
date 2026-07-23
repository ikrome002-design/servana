<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Enums\ClientStatus;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('messaging', 'sms', 'phase21s', 'own-scope');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 | Plan §64: a Personnel user sees ONLY clients they PERSONALLY SERVED — at least one COMPLETED
 | service session performed by their own staff profile. These tests prove each half of that
 | sentence, plus the masking and anti-enumeration properties ADR-010 requires.
 */

function servedClientsResponse(array $scn, array $query = [])
{
    return test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/served-clients/sms?'.http_build_query($query));
}

it('lists a client the personnel member personally served, with MASKED contact only', function (): void {
    $scn = smsScenario();

    $response = servedClientsResponse($scn)->assertOk();

    $response->assertJsonPath('data.0.id', $scn['client']->ulid);
    $response->assertJsonPath('data.0.phone_masked', '••• ••• 5678');

    // The full number, the ciphertext and the blind index never appear (ADR-010).
    $body = $response->getContent();
    expect($body)->not->toContain('+254712345678')
        ->not->toContain('712345678')
        ->not->toContain('phone_encrypted')
        ->not->toContain('phone_index')
        ->not->toContain('email');
});

it('excludes a client served by a DIFFERENT personnel member', function (): void {
    $scn = smsScenario();

    // A second personnel member in the same branch, with their own served client.
    [, , $otherStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    $otherClient = smsServedClient($scn['merchant'], $scn['branch'], $otherStaff, $scn['service'], phone: '+254799887766');

    $response = servedClientsResponse($scn)->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($scn['client']->ulid)
        ->not->toContain($otherClient->ulid);
});

it('excludes a client whose only session is NOT completed', function (ServiceSessionStatus $status): void {
    // One scenario per status: `service_sessions_active_staff_unique` allows a staff profile only
    // ONE active session at a time, so pending and in_progress cannot coexist for the same staff.
    $scn = smsScenario();
    $client = smsServedClient(
        $scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'],
        sessionStatus: $status,
        phone: '+254720000001',
    );

    $ids = collect(servedClientsResponse($scn)->json('data'))->pluck('id')->all();

    expect($ids)
        ->not->toContain($client->ulid, "a {$status->value} session must not make a client 'served'")
        // the completed-session client from the scenario is still there — the query works
        ->toContain($scn['client']->ulid);
})->with([
    'pending' => ServiceSessionStatus::Pending,
    'in progress' => ServiceSessionStatus::InProgress,
    'cancelled' => ServiceSessionStatus::Cancelled,
]);

it('excludes an archived client from the list', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['status' => ClientStatus::Archived]);

    servedClientsResponse($scn)->assertOk()->assertJsonCount(0, 'data');
});

it('excludes a client of another BRANCH the personnel member is not assigned to', function (): void {
    $scn = smsScenario();

    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    $otherService = Service::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $otherBranch->id]);
    $otherClient = smsServedClient($scn['merchant'], $otherBranch, $scn['staff'], $otherService, phone: '+254733111222');

    $ids = collect(servedClientsResponse($scn)->json('data'))->pluck('id')->all();

    expect($ids)->toContain($scn['client']->ulid)->not->toContain($otherClient->ulid);
});

it('excludes a client of another MERCHANT entirely', function (): void {
    $scn = smsScenario();
    $other = smsScenario();

    $ids = collect(servedClientsResponse($scn)->json('data'))->pluck('id')->all();

    expect($ids)->toContain($scn['client']->ulid)->not->toContain($other['client']->ulid);
});

it('still lists a served client who has NOT consented — consent gates SENDING, not visibility', function (): void {
    $scn = smsScenario();
    $optedOut = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], ConsentState::OptedOut, phone: '+254700111222');
    $noConsent = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], null, phone: '+254700333444');

    $ids = collect(servedClientsResponse($scn)->json('data'))->pluck('id')->all();

    expect($ids)->toContain($optedOut->ulid)->toContain($noConsent->ulid);
});

it('searches by NAME only, and cannot be searched by phone', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiru']);
    $other = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], phone: '+254701020304');
    $other->update(['full_name' => 'Brian Otieno']);

    $byName = collect(servedClientsResponse($scn, ['search' => 'Amina'])->json('data'))->pluck('id')->all();
    expect($byName)->toBe([$scn['client']->ulid]);

    // A phone fragment matches NOTHING — there is no phone search path at all (ADR-010, §73).
    foreach (['0701020304', '701020304', '+254701020304', '0304'] as $fragment) {
        $byPhone = servedClientsResponse($scn, ['search' => $fragment])->json('data');
        expect($byPhone)->toBe([], "search must never resolve a phone fragment ({$fragment})");
    }
});

it('escapes LIKE metacharacters so a search term cannot widen its own pattern', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiru']);

    // A bare `%` would match everything if it were interpolated unescaped.
    expect(servedClientsResponse($scn, ['search' => '%'])->json('data'))->toBe([]);
    expect(servedClientsResponse($scn, ['search' => '_'])->json('data'))->toBe([]);
});

it('rejects a client-supplied staff identifier — own scope is always derived', function (): void {
    $scn = smsScenario();
    [, , $otherStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    $otherClient = smsServedClient($scn['merchant'], $scn['branch'], $otherStaff, $scn['service'], phone: '+254755667788');

    // Every shape a caller might try to impersonate another staff profile with is either ignored
    // (unknown query parameter) or rejected — never honoured.
    foreach ([
        ['staff_profile_id' => $otherStaff->id],
        ['staff_profile_ulid' => $otherStaff->ulid],
        ['staff_id' => $otherStaff->id],
    ] as $attempt) {
        $ids = collect(servedClientsResponse($scn, $attempt)->json('data'))->pluck('id')->all();

        expect($ids)->not->toContain($otherClient->ulid)->toContain($scn['client']->ulid);
    }
});

it('denies the served-client list without the permission', function (): void {
    $scn = smsScenario();

    // A Front Office member has no personnel own-scope key at all.
    [$frontOffice] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::FrontOffice);

    test()->actingAs($frontOffice, 'sanctum')->getJson('/api/v1/personnel/me/served-clients/sms')->assertForbidden();
});

it('paginates and allowlists sorts', function (): void {
    $scn = smsScenario();

    test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/served-clients/sms?per_page=1')->assertOk();
    test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/served-clients/sms?per_page=1000')->assertStatus(422);
    test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/served-clients/sms?sort=full_name')->assertOk();
    // A contact column is not a sortable field.
    test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/served-clients/sms?sort=phone_last_four')->assertStatus(422);
});
