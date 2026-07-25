<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Http\Requests\Search\SearchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('search', 'phase22', 'security');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | Filter forgery and injection (Plan §68 allowlisted sort/filter; §24.2).
 |
 | Two independent guarantees:
 |   1. a forgery field is REJECTED (422) rather than silently ignored, so its
 |      ineffectiveness is positive evidence rather than an absence of proof;
 |   2. even if one were tolerated, it could not change the executed query,
 |      because every filter is built from the authenticated membership.
 |==============================================================================
 */

it('rejects every scope, permission and engine field by name', function (string $field): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => 'Amina', $field => 'anything'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => [$field]]]);
})->with(SearchRequest::PROHIBITED_FIELDS);

it('lists every forgery field the security spec names', function (): void {
    // Keeps the code and docs/architecture/search/search-security.md §2 in lockstep: a field added
    // to the doc without the code (or the reverse) fails here.
    expect(SearchRequest::PROHIBITED_FIELDS)->toEqualCanonicalizing([
        'merchant_id', 'merchant_ulid', 'branch_id', 'branch_ids',
        'staff_profile_id', 'staff_profile_ulid',
        'permission', 'permissions', 'role',
        'filter', 'filters', 'raw_filter', 'index', 'api_key',
        'include_sensitive', 'include_phone', 'include_email',
        'export', 'download', 'print', 'copy',
    ]);
});

it('cannot be made to cross a tenant boundary with a forged merchant id', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    search($scn['frontOffice'], [
        'q' => 'Amina',
        'merchant_id' => $foreign['merchant']->id,
    ])->assertStatus(422);

    // And the honest query still returns only the caller's own row.
    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();

    expect(searchResultUlids($response, 'client'))
        ->toContain($scn['clientA']->ulid)
        ->not->toContain($foreign['client']->ulid);
});

it('cannot be made to cross a branch boundary with a forged branch id', function (): void {
    $scn = searchScenario();

    search($scn['frontOffice'], [
        'q' => 'Amina',
        'branch_id' => $scn['branchB']->id,
    ])->assertStatus(422);
});

it('cannot be made to impersonate another staff profile', function (): void {
    $scn = smsScenario();
    $scn['client']->update(['full_name' => 'Amina Wanjiku']);

    [$other, , $otherStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);

    search($other, [
        'q' => 'Amina',
        'types' => ['served_client'],
        'staff_profile_id' => $scn['staff']->id,
    ])->assertStatus(422);

    // The honest own-scope query for the other member returns nothing of the first member's.
    $response = search($other, ['q' => 'Amina', 'types' => ['served_client']])->assertOk();

    expect(searchResultUlids($response, 'served_client'))->not->toContain($scn['client']->ulid);
    expect($otherStaff->id)->not->toBe($scn['staff']->id);
});

it('cannot be granted a permission it does not hold', function (): void {
    $scn = searchScenario();

    search($scn['frontOffice'], [
        'q' => 'Amina',
        'permission' => 'branches.manage_users_lifecycle',
    ])->assertStatus(422);
});

/*
 |--------------------------------------------------------------------------
 | LIKE metacharacters cannot widen their own pattern
 |--------------------------------------------------------------------------
 */

it('treats a LIKE wildcard as a literal, so it cannot dump the branch', function (string $term): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], ['q' => $term, 'types' => ['client']])->assertOk();

    // `%` matching every row would be the difference between search and exfiltration.
    expect(searchResultUlids($response, 'client'))->not->toContain($scn['clientA']->ulid);
})->with([
    'percent' => '%%',
    'percent with text' => '%Zzz%',
    'underscore run' => '____',
    'escaped percent' => '\\%',
]);

it('still matches a term that legitimately contains a wildcard character', function (): void {
    $scn = searchScenario();
    $scn['clientA']->update(['full_name' => '100% Cotton Salon']);

    $response = search($scn['frontOffice'], ['q' => '100%', 'types' => ['client']])->assertOk();

    expect(searchResultUlids($response, 'client'))->toContain($scn['clientA']->ulid);
});

/*
 |--------------------------------------------------------------------------
 | Engine filter syntax and SQL cannot be reached through the term
 |--------------------------------------------------------------------------
 */

it('cannot escape the tenancy filter through Meilisearch filter syntax in the term', function (string $term): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    $response = search($scn['frontOffice'], ['q' => $term])->assertOk();

    expect(searchResultUlids($response))->not->toContain($foreign['client']->ulid);
})->with([
    'or clause' => 'Amina" OR merchant_id = 1 OR "',
    'filter injection' => 'Amina OR merchant_id EXISTS',
    'bracket escape' => 'Amina] OR branch_id IN [1,2,3',
    'not clause' => 'Amina NOT merchant_id = 0',
]);

it('cannot reach SQL through the term', function (string $term): void {
    $scn = searchScenario();

    // The term is always a bound parameter, never interpolated, so these are ordinary text.
    search($scn['frontOffice'], ['q' => $term])->assertOk();
})->with([
    'quote' => "Amina' OR '1'='1",
    'union' => 'Amina UNION SELECT phone_encrypted FROM clients',
    'comment' => 'Amina--',
    'semicolon' => 'Amina; DROP TABLE clients',
    'cast' => "Amina'::text",
]);

it('strips control characters rather than passing them through', function (): void {
    $scn = searchScenario();

    // A NUL-padded term normalizes to the plain name and still matches.
    $response = search($scn['frontOffice'], ['q' => "Am\x00ina\x1b", 'types' => ['client']])->assertOk();

    expect($response->json('meta.query'))->not->toContain("\x00")
        ->and($response->json('meta.query'))->not->toContain("\x1b");
});

it('collapses whitespace so a padded term is one query', function (): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => '  Amina   Wanjiku  '])
        ->assertOk()
        ->assertJsonPath('meta.query', 'Amina Wanjiku');
});
