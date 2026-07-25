<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Clients\Models\Client;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Http\Controllers\Api\V1\Search\SearchController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class)->group('search', 'phase22', 'security', 'contact-export');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | Exact phone lookup — the ONLY phone-search path (search-security.md §4).
 |
 | It exists because Front-Office speed search needs it, and it is deliberately
 | shaped so it cannot become an enumeration oracle or a contact-export surrogate
 | (ADR-010; Plan §73 "personnel contact extraction").
 |==============================================================================
 */

const P22_FULL_PHONE = '+254712345678';

it('finds the client by a COMPLETE phone number, through the blind index', function (string $written): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], ['q' => $written])->assertOk();

    expect(searchResultUlids($response, 'client'))->toContain($scn['clientA']->ulid);
})->with([
    'e164' => P22_FULL_PHONE,
    'e164 without plus' => '254712345678',
    'kenyan local' => '0712345678',
    'national significant' => '712345678',
    'with separators' => '0712 345 678',
]);

it('returns the client NAME and no phone in any form on the phone path', function (): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], ['q' => P22_FULL_PHONE])->assertOk();
    $body = $response->getContent();

    expect($response->json('data.0.title'))->toBe('Amina Wanjiku')
        ->and($body)->not->toContain('712345678')
        ->and($body)->not->toContain('2345678')
        ->and($body)->not->toContain('5678')
        ->and($body)->not->toContain('phone');
});

it('redacts a phone-like term out of meta.query instead of echoing it back', function (): void {
    $scn = searchScenario();

    // Echoing the term would put a client's phone number in the response body, and therefore in
    // anything that stores a response (Plan §64; ADR-010).
    search($scn['frontOffice'], ['q' => P22_FULL_PHONE])
        ->assertOk()
        ->assertJsonPath('meta.query', SearchController::REDACTED_QUERY);

    // A non-phone term is still echoed, so the redaction is targeted rather than blanket.
    search($scn['frontOffice'], ['q' => 'Amina'])
        ->assertOk()
        ->assertJsonPath('meta.query', 'Amina');
});

it('never returns another branch client on the phone path', function (): void {
    $scn = searchScenario();

    // Branch B's client owns +254733111222; the actor reaches branch A only.
    $response = search($scn['frontOffice'], ['q' => '+254733111222'])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('never returns another merchant client on the phone path', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    $response = search($scn['frontOffice'], ['q' => '+254799888777'])->assertOk();

    expect($response->json('data'))->toBe([])
        ->and(searchResultUlids($response))->not->toContain($foreign['client']->ulid);
});

/*
 |--------------------------------------------------------------------------
 | Partial phone numbers are NOT searchable — anywhere
 |--------------------------------------------------------------------------
 | A digit-by-digit confirmation oracle is the exact threat ADR-010 exists to
 | prevent, so only a WHOLE number reaches the exact-lookup path, and no phone
 | digit is indexed anywhere, so a fragment cannot match through the text path
 | either.
 */

it('does not resolve a partial phone fragment to the client', function (string $fragment): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], ['q' => $fragment])->assertOk();

    expect(searchResultUlids($response, 'client'))->not->toContain($scn['clientA']->ulid);
})->with([
    'last four' => '5678',
    'first six' => '071234',
    'middle run' => '123456',
    'seven digits' => '2345678',
    'eight digits' => '12345678',
]);

it('leaves a numeric term free to match a business number instead of a phone', function (): void {
    $scn = searchScenario();

    // A short numeric term is NOT phone-like, so it goes down the ordinary text path and can match
    // an invoice or receipt number rather than being swallowed by the phone branch.
    $response = search($scn['frontOffice'], ['q' => '1024'])->assertOk();

    expect($response->json('meta.query'))->toBe('1024');
});

/*
 |--------------------------------------------------------------------------
 | Authority + non-enumeration
 |--------------------------------------------------------------------------
 */

it('refuses the phone path to a role without the client search pair', function (): void {
    $scn = searchScenario();

    [$manager] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::BranchManager);

    $response = search($manager, ['q' => P22_FULL_PHONE])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('answers an unknown number exactly like an unauthorized one', function (): void {
    $scn = searchScenario();
    [$manager] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::BranchManager);

    $unknown = search($scn['frontOffice'], ['q' => '+254700000000'])->assertOk();
    $unauthorized = search($manager, ['q' => P22_FULL_PHONE])->assertOk();

    expect($unknown->json('data'))->toBe([])
        ->and($unauthorized->json('data'))->toBe([])
        ->and($unknown->getStatusCode())->toBe($unauthorized->getStatusCode());
});

it('reports only the client type on the phone path, never a whole-catalogue sweep', function (): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], ['q' => P22_FULL_PHONE])->assertOk();

    // A phone number cannot legitimately match a service, an invoice number or a reference, so the
    // phone path answers for `client` alone — and the engine is not consulted at all.
    expect($response->json('meta.types'))->toBe(['client']);
});

it('never logs the search term, so a phone number cannot reach a log line', function (): void {
    $scn = searchScenario();

    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    search($scn['frontOffice'], ['q' => P22_FULL_PHONE])->assertOk();

    foreach ($logged as $line) {
        expect($line)->not->toContain('712345678')
            ->and($line)->not->toContain('254712345678');
    }
});

/*
 |--------------------------------------------------------------------------
 | The blind index itself is never exposed
 |--------------------------------------------------------------------------
 */

it('never returns or indexes the blind-index digest', function (): void {
    $scn = searchScenario();

    /** @var Client $client */
    $client = Client::query()->whereKey($scn['clientA']->id)->first();
    $digest = $client->getAttribute('phone_index');

    expect($digest)->toBeString()->and($digest)->not->toBeEmpty();

    $response = search($scn['frontOffice'], ['q' => P22_FULL_PHONE])->assertOk();

    expect($response->getContent())->not->toContain((string) $digest);
});
