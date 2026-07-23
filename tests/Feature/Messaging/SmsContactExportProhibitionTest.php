<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionRegistry;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('messaging', 'sms', 'phase21s', 'security', 'contact-export');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | THE PHASE-DEFINING INVARIANT (ADR-010; Plan §19.4 non-overridable; §64; §73)
 |
 | Personnel contact export does not exist. Not "is unauthorised" — does not exist:
 | no schema field, no endpoint, no permission, no UI control, and no response,
 | log or audit row from which a phone list could be reconstructed.
 |==============================================================================
 */

/** The full number used by smsScenario()'s client — the value that must never appear. */
const P21S_FULL_PHONE = '+254712345678';

const P21S_PHONE_DIGITS = '712345678';

/*
 |--------------------------------------------------------------------------
 | No export ROUTE exists — and a guess is audited at high severity
 |--------------------------------------------------------------------------
 */

it('registers no export/download/print/copy route anywhere in the SMS or served-client surface', function (): void {
    $offenders = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower($route->uri());

        if (! str_contains($uri, 'sms') && ! str_contains($uri, 'served-client')) {
            continue;
        }

        foreach (['export', 'download', 'print', 'copy', 'csv', 'xlsx', 'pdf', 'vcard', 'contacts'] as $token) {
            if (str_contains($uri, $token)) {
                $offenders[] = $route->methods()[0].' '.$uri;
            }
        }
    }

    expect($offenders)->toBe([], 'The SMS surface must expose no export-shaped route');
});

it('404s every guessed export-shaped route AND audits the attempt at HIGH severity', function (string $path): void {
    $scn = smsScenario();

    test()->actingAs($scn['user'], 'sanctum')->getJson($path)->assertNotFound();

    $log = AuditLog::query()
        ->where('action', AuditEvent::PersonnelSmsExportAttemptBlocked->value)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull("a probe of {$path} must be audited")
        ->and($log->severity->value)->toBe('high')
        ->and($log->context['path'] ?? null)->toBe($path)
        ->and($log->context['outcome'] ?? null)->toBe('not_found');
})->with([
    '/api/v1/personnel/me/served-clients/export',
    '/api/v1/personnel/me/served-clients/sms/export',
    '/api/v1/personnel/me/served-clients/download',
    '/api/v1/personnel/me/served-clients/csv',
    '/api/v1/personnel/me/sms-campaigns/export',
    '/api/v1/personnel/me/sms/download',
    '/api/v1/personnel/me/sms/print',
    '/api/v1/personnel/me/sms/copy',
    '/api/v1/personnel/me/sms/contacts',
    '/api/v1/personnel/me/sms-campaigns/phones',
]);

it('does NOT mistake a legitimate download route for a contact-export probe', function (): void {
    $scn = smsScenario();

    // A mistyped payout-statement download is a 404, but it is NOT a contact-extraction attempt and
    // must not be recorded as one — the detector is scoped to the SMS/served-client surface.
    test()->actingAs($scn['user'], 'sanctum')
        ->getJson('/api/v1/files/does-not-exist/download')
        ->assertNotFound();

    expect(AuditLog::query()->where('action', AuditEvent::PersonnelSmsExportAttemptBlocked->value)->exists())
        ->toBeFalse();
});

/*
 |--------------------------------------------------------------------------
 | No API response carries a full phone number
 |--------------------------------------------------------------------------
 */

it('never returns a full phone from ANY Phase 21S endpoint', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    smsConfirm($scn['user'], $ulid);

    $responses = [
        'served clients' => test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/served-clients/sms'),
        'campaign list' => test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/sms-campaigns'),
        'campaign detail' => test()->actingAs($scn['user'], 'sanctum')->getJson("/api/v1/personnel/me/sms-campaigns/{$ulid}"),
        'recipients' => test()->actingAs($scn['user'], 'sanctum')->getJson("/api/v1/personnel/me/sms-campaigns/{$ulid}/recipients"),
        'preview' => test()->actingAs($scn['user'], 'sanctum')->postJson('/api/v1/personnel/me/sms-campaigns/preview', [
            'client_ulids' => [$scn['client']->ulid],
            'message_body' => 'Hello',
        ]),
    ];

    foreach ($responses as $label => $response) {
        $body = $response->assertSuccessful()->getContent();

        expect($body)
            ->not->toContain(P21S_FULL_PHONE, "{$label} must not contain the full phone")
            ->not->toContain(P21S_PHONE_DIGITS, "{$label} must not contain the national number")
            ->not->toContain('phone_encrypted', "{$label} must not expose the ciphertext column")
            ->not->toContain('phone_index', "{$label} must not expose the blind index")
            ->not->toContain('provider_message_id', "{$label} must not expose the provider handle");
    }
});

it('returns the recipient list masked, with only the last four digits', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    $response = test()->actingAs($scn['user'], 'sanctum')
        ->getJson("/api/v1/personnel/me/sms-campaigns/{$ulid}/recipients")
        ->assertOk();

    $response->assertJsonPath('data.0.phone_masked', '••• ••• 5678');

    // Exactly four digits appear in the masked value — no more.
    $masked = (string) $response->json('data.0.phone_masked');
    expect(preg_replace('/\D/', '', $masked))->toBe('5678');
});

it('never serializes the delivery snapshot, even through the model itself', function (): void {
    $scn = smsScenario();
    smsDraft($scn['user'], [$scn['client']->ulid]);

    $recipient = PersonnelSmsRecipient::query()->firstOrFail();

    // The value IS there for delivery...
    expect($recipient->phone_encrypted)->toBe(P21S_FULL_PHONE);

    // ...but it can never reach an array/JSON representation.
    expect(array_key_exists('phone_encrypted', $recipient->toArray()))->toBeFalse()
        ->and(json_encode($recipient))->not->toContain(P21S_PHONE_DIGITS);

    // The campaign carries no contact at all, and never its message body.
    $campaign = PersonnelSmsCampaign::query()->firstOrFail();
    expect(json_encode($campaign))
        ->not->toContain(P21S_PHONE_DIGITS)
        ->not->toContain('message_body');
});

/*
 |--------------------------------------------------------------------------
 | No phone in logs or audit contexts
 |--------------------------------------------------------------------------
 */

it('writes no phone number into ANY audit context across the whole flow', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    smsConfirm($scn['user'], $ulid);

    $logs = AuditLog::query()->where('action', 'like', 'personnel.sms.%')->get();

    expect($logs)->not->toBeEmpty();

    foreach ($logs as $log) {
        $context = json_encode($log->context);

        expect($context)
            ->not->toContain(P21S_FULL_PHONE)
            ->not->toContain(P21S_PHONE_DIGITS)
            ->not->toContain('5678')
            // No client identity either — an audit row must not become a contact record.
            ->not->toContain($scn['client']->ulid)
            ->not->toContain($scn['client']->full_name);
    }
});

it('writes no phone number into the application log during a full send', function (): void {
    $scn = smsScenario();

    $captured = [];
    Log::listen(function ($message) use (&$captured): void {
        $captured[] = $message->message.' '.json_encode($message->context);
    });

    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    smsConfirm($scn['user'], $ulid);

    $joined = implode("\n", $captured);

    // Asserted unconditionally, so an empty capture cannot make this test vacuously pass.
    expect($joined)->not->toContain(P21S_FULL_PHONE)->not->toContain(P21S_PHONE_DIGITS);
    // And the flow really did run end to end.
    expect(PersonnelSmsCampaign::query()->where('ulid', $ulid)->exists())->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | No phone in a URL, and no phone accepted INTO the API
 |--------------------------------------------------------------------------
 */

it('accepts no phone-shaped input field on any SMS endpoint', function (string $field): void {
    $scn = smsScenario();

    test()->actingAs($scn['user'], 'sanctum')->postJson('/api/v1/personnel/me/sms-campaigns', [
        'client_ulids' => [$scn['client']->ulid],
        'message_body' => 'Hello',
        $field => P21S_FULL_PHONE,
    ])->assertStatus(422);
})->with(['phone', 'phone_encrypted', 'phone_last_four']);

it('binds campaigns by ULID only, so no identifier in a URL can be a contact', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();

    // The internal numeric id is not a route key.
    test()->actingAs($scn['user'], 'sanctum')
        ->getJson("/api/v1/personnel/me/sms-campaigns/{$campaign->id}")
        ->assertNotFound();

    test()->actingAs($scn['user'], 'sanctum')
        ->getJson("/api/v1/personnel/me/sms-campaigns/{$ulid}")
        ->assertOk();
});

/*
 |--------------------------------------------------------------------------
 | No export PERMISSION exists — for anyone
 |--------------------------------------------------------------------------
 */

it('has no contact-export permission key in the catalogue, for any role', function (): void {
    $registry = app(PermissionRegistry::class);

    foreach ($registry->permissionKeys() as $key) {
        $lower = strtolower($key);

        $isContactish = str_contains($lower, 'client') || str_contains($lower, 'contact')
            || str_contains($lower, 'served') || str_contains($lower, 'sms');
        $isExportish = str_contains($lower, 'export') || str_contains($lower, 'download')
            || str_contains($lower, 'print');

        expect($isContactish && $isExportish)->toBeFalse("{$key} looks like a contact-export key");
    }

    // Personnel hold exactly two SMS-surface keys and nothing export-shaped.
    $personnel = $registry->defaultGrantsFor(PermissionRegistry::ROLE_PERSONNEL);
    expect($personnel)->toContain('personnel.my_served_clients.view')->toContain('personnel.my_sms.send');

    foreach ($personnel as $key) {
        expect(str_contains($key, 'export'))->toBeFalse("Personnel must never hold {$key}");
        expect(str_contains($key, 'download') && ! str_contains($key, 'my_statements'))
            ->toBeFalse("Personnel must never hold {$key}");
    }
});

it('grants the two SMS keys to PERSONNEL ONLY', function (): void {
    $registry = app(PermissionRegistry::class);

    foreach ($registry->roleKeys() as $role) {
        $grants = array_merge($registry->defaultGrantsFor($role), $registry->grantableFor($role));

        foreach (['personnel.my_served_clients.view', 'personnel.my_sms.send'] as $key) {
            // Boolean form: `toContain` reads extra arguments as further expected values.
            $holds = in_array($key, $grants, true);

            expect($holds)->toBe(
                $role === PermissionRegistry::ROLE_PERSONNEL,
                "{$role} and {$key}: only personnel may hold this key",
            );
        }
    }
});

/*
 |--------------------------------------------------------------------------
 | The OpenAPI contract carries no contact either
 |--------------------------------------------------------------------------
 */

it('publishes no full phone number in the committed OpenAPI document', function (): void {
    $spec = file_get_contents(base_path('docs/api/openapi.json'));

    expect($spec)
        ->not->toContain(P21S_FULL_PHONE)
        ->not->toContain('phone_encrypted')
        ->not->toContain('phone_index');
});
