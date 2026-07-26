<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Services\SearchQueryParser;
use App\Http\Requests\Search\SearchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('search', 'phase22');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | GET /api/v1/search — the aggregator contract (Plan §68; §80 Phase 22; D-22-01).
 |
 | The route grants access to NOTHING. It is authenticated, tenant-scoped,
 | active-membership-gated and rate-limited; every result type is admitted only
 | after the server proves the caller already holds the authority governing that
 | type's own list/detail route.
 |==============================================================================
 */

it('requires authentication', function (): void {
    $this->getJson('/api/v1/search?q=amina')->assertUnauthorized();
});

it('returns the caller own-branch client and the documented meta envelope', function (): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], ['q' => 'Amina']);

    $response->assertOk()
        ->assertJsonPath('meta.query', 'Amina')
        ->assertJsonPath('meta.limit', SearchRequest::DEFAULT_LIMIT)
        ->assertJsonPath('meta.next_cursor', null);

    expect(searchResultUlids($response, 'client'))->toContain($scn['clientA']->ulid);
});

it('shapes every result with the published keys and nothing else', function (): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['client']])->assertOk();

    /** @var array<string, mixed> $row */
    $row = $response->json('data.0');

    expect(array_keys($row))->toEqualCanonicalizing([
        'type', 'type_label', 'ulid', 'title', 'subtitle', 'snippet',
        'status', 'date', 'amount', 'route', 'branch',
    ])
        ->and($row['type'])->toBe('client')
        ->and($row['type_label'])->toBe('Client')
        ->and($row['title'])->toBe('Amina Wanjiku')
        ->and($row['route']['name'])->toBe('front-office.clients.detail')
        ->and($row['route']['id'])->toBe($scn['clientA']->ulid)
        ->and($row['branch']['ulid'])->toBe($scn['branchA']->ulid);
});

/*
 |--------------------------------------------------------------------------
 | NO CONTACT FIELD EXISTS IN THE RESPONSE (decision D-22-03; ADR-010; §74)
 |--------------------------------------------------------------------------
 | Several canonical Resources DO return masked client contact today. Search
 | returns none of it, so this is a property of the schema rather than a
 | per-branch condition — one assertion covers every type at once.
 */

it('never returns a contact field of any kind, for any type', function (): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();

    $body = $response->getContent();

    expect($body)->not->toContain('phone')
        ->and($body)->not->toContain('email')
        ->and($body)->not->toContain('254712345678')
        ->and($body)->not->toContain('712345678')
        ->and($body)->not->toContain('2345678')
        ->and($body)->not->toContain('phone_index')
        ->and($body)->not->toContain('phone_last_four')
        ->and($body)->not->toContain('phone_encrypted')
        ->and($body)->not->toContain('email_encrypted');
});

it('exposes no export, download, print or copy affordance on the search surface', function (): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();
    $body = strtolower($response->getContent());

    foreach (['export', 'download', 'print', 'clipboard', 'csv', 'xlsx', 'vcard'] as $token) {
        expect($body)->not->toContain($token);
    }
});

it('registers no export-shaped search route, and 404s a guess', function (string $path): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();

    test()->actingAs($scn['frontOffice'], 'sanctum')->getJson($path)->assertNotFound();
})->with([
    '/api/v1/search/export',
    '/api/v1/search/download',
    '/api/v1/search/csv',
    '/api/v1/search/contacts',
    '/api/v1/search/phones',
]);

/*
 |--------------------------------------------------------------------------
 | Non-enumerating posture: no authority ⇒ 200 empty, never 403
 |--------------------------------------------------------------------------
 */

it('returns 200 with an empty collection when the caller can search nothing', function (): void {
    $scn = searchScenario();

    // Audit holds `receipt.view` but none of the other catalogue authorities, and there is no
    // receipt in this scenario — so the effective type set is non-empty yet the result is empty.
    [$audit] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Audit);

    $response = search($audit, ['q' => 'Amina'])->assertOk();

    expect($response->json('data'))->toBe([])
        ->and(searchResultTypes($response))->toBe([]);
});

it('answers a zero-result query exactly like an unauthorized one', function (): void {
    $scn = searchScenario();

    $noMatch = search($scn['frontOffice'], ['q' => 'Zzzznotathing'])->assertOk();
    [$audit] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Audit);
    $noAuthority = search($audit, ['q' => 'Amina'])->assertOk();

    // Same status and same empty payload: an attacker cannot tell the two apart.
    expect($noMatch->json('data'))->toBe([])
        ->and($noAuthority->json('data'))->toBe([])
        ->and($noMatch->getStatusCode())->toBe($noAuthority->getStatusCode());
});

/*
 |--------------------------------------------------------------------------
 | Request validation (allowlists + bounds)
 |--------------------------------------------------------------------------
 */

it('requires a query term', function (): void {
    $scn = searchScenario();

    search($scn['frontOffice'], [])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['q']]]);
});

it('rejects a term shorter than the minimum or longer than the maximum', function (string $q): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => $q])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['q']]]);
})->with([
    'single character' => 'a',
    'over the cap' => [str_repeat('a', SearchQueryParser::MAX_LENGTH + 1)],
]);

it('rejects an unknown document type rather than ignoring it', function (): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['wallet_payment']])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['types.0']]]);
});

it('rejects an unknown sort token', function (): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => 'Amina', 'sort' => 'total_minor desc'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['sort']]]);
});

it('bounds the per-type limit', function (): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => 'Amina', 'limit' => SearchRequest::MAX_LIMIT + 1])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['limit']]]);
});

it('accepts both allowlisted sorts', function (string $sort): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => 'Amina', 'sort' => $sort])->assertOk();
})->with(['relevance', 'recent']);

it('publishes exactly the live catalogue types in the request allowlist', function (): void {
    // The enum IS the allowlist, so a deferred type cannot be requested. `service` in particular is
    // absent (no SPA detail screen), and no integration type exists at all.
    expect(SearchDocumentType::values())->toBe([
        'client', 'staff', 'appointment', 'queue_entry', 'service_session',
        'invoice', 'receipt', 'served_client',
    ]);
});
