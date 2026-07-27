<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('merchants', 'merchant-profile', 'rem-scr-002');

/*
 |==============================================================================
 | REM-SCR-002A — merchant business profile (Plan §27.3 Merchant Administrator
 | "merchant profile"; §19.3:1444-1445 merchant.profile.view / merchant.profile.update).
 |
 | The Plan-mandated launch screen was never built although its owning phase was recorded
 | verified_complete. These are the behavioural tests for the corrective surface. File-local
 | helpers carry unique names (a Pest file-scope function is a GLOBAL function; cf. PH23-TEST-001).
 */

const MP_URL = '/api/v1/merchant/profile';

/** A merchant with a filled profile row plus its Merchant Administrator. */
function mpScenario(): array
{
    [$admin, $merchant] = activeAdmin();

    $profile = MerchantProfile::query()->create([
        'merchant_id' => $merchant->id,
        'business_category' => 'Salon',
        'contact_phone' => '+254700000111',
        'contact_email' => 'owner@glow.test',
        'receipt_display_name' => 'Glow Salon',
        'address' => '1 Kenyatta Avenue',
        'town' => 'Nairobi',
        'country' => 'KE',
        'timezone' => 'Africa/Nairobi',
    ]);

    return compact('admin', 'merchant', 'profile');
}

it('requires authentication on both the read and the update', function (): void {
    test()->getJson(MP_URL)->assertUnauthorized();
    test()->patchJson(MP_URL, ['town' => 'Mombasa'])->assertUnauthorized();
});

it('lets the Merchant Administrator read its own profile with no internal id or storage path', function (): void {
    $scn = mpScenario();

    $body = test()->actingAs($scn['admin'], 'sanctum')->getJson(MP_URL)
        ->assertOk()
        ->assertJsonPath('data.business_category', 'Salon')
        ->assertJsonPath('data.contact_phone', '+254700000111')
        ->assertJsonPath('data.town', 'Nairobi')
        ->assertJsonPath('data.country', 'KE')
        ->assertJsonPath('data.merchant.name', $scn['merchant']->name)
        ->json();

    $raw = json_encode($body);
    expect($body['data']['id'])->toBe($scn['profile']->ulid);
    foreach (['merchant_id', 'logo_path', 'final_path', 'quarantine_path', 'storage_disk', 'sha256'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }
    // The internal primary key must never appear as the public identifier.
    expect($body['data']['id'])->not->toBe((string) $scn['profile']->id);
});

it('updates only the allowlisted fields and ignores everything else', function (): void {
    $scn = mpScenario();

    test()->actingAs($scn['admin'], 'sanctum')->patchJson(MP_URL, [
        'town' => 'Mombasa',
        'receipt_display_name' => 'Glow Coast',
        // Every one of these is NOT writable through this surface.
        'country' => 'UG',
        'merchant_id' => 999999,
        'business_name' => 'Hijacked',
        'service_fee_tier' => 'business_centric',
        'status' => 'suspended',
        'billing_status' => 'active',
    ])->assertOk()
        ->assertJsonPath('data.town', 'Mombasa')
        ->assertJsonPath('data.receipt_display_name', 'Glow Coast')
        ->assertJsonPath('data.country', 'KE');

    $scn['profile']->refresh();
    $scn['merchant']->refresh();

    expect($scn['profile']->town)->toBe('Mombasa')
        ->and($scn['profile']->country)->toBe('KE')
        ->and($scn['profile']->merchant_id)->toBe($scn['merchant']->id)
        ->and($scn['merchant']->service_fee_tier?->value)->not->toBe('business_centric')
        ->and($scn['merchant']->billing_status)->toBe(MerchantBillingStatus::Trialing);
});

it('audits the change exactly once, with field NAMES only and never the values', function (): void {
    $scn = mpScenario();

    test()->actingAs($scn['admin'], 'sanctum')
        ->patchJson(MP_URL, ['contact_phone' => '+254700000222'])->assertOk();

    $rows = AuditLog::query()->where('action', AuditEvent::MerchantProfileUpdated->value)->get();
    expect($rows)->toHaveCount(1);

    $context = json_encode($rows->first()->context);
    expect($context)->toContain('contact_phone')          // the NAME is recorded
        ->and($context)->not->toContain('+254700000222')   // the VALUE never is
        ->and($context)->not->toContain('+254700000111');  // nor the previous value
});

it('writes no audit row and no change when the payload changes nothing', function (): void {
    $scn = mpScenario();

    test()->actingAs($scn['admin'], 'sanctum')
        ->patchJson(MP_URL, ['town' => 'Nairobi'])->assertOk();

    expect(AuditLog::query()->where('action', AuditEvent::MerchantProfileUpdated->value)->count())->toBe(0);
});

it('validates the payload and rejects a blanked required field', function (): void {
    $scn = mpScenario();
    $actor = test()->actingAs($scn['admin'], 'sanctum');

    $actor->patchJson(MP_URL, ['contact_email' => 'not-an-email'])->assertStatus(422);
    $actor->patchJson(MP_URL, ['business_category' => 'x'])->assertStatus(422);   // min:2
    $actor->patchJson(MP_URL, ['contact_phone' => '123'])->assertStatus(422);     // min:7
    $actor->patchJson(MP_URL, ['timezone' => 'Mars/Olympus'])->assertStatus(422); // real timezone
    $actor->patchJson(MP_URL, ['town' => str_repeat('a', 81)])->assertStatus(422);

    // …and an optional field CAN be cleared.
    $actor->patchJson(MP_URL, ['address' => null])->assertOk()->assertJsonPath('data.address', null);
});

it('denies every role except the Merchant Administrator, on both the read and the update', function (): void {
    $scn = mpScenario();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);

    foreach ([
        MerchantUserRole::BranchManager,
        MerchantUserRole::Hr,
        MerchantUserRole::Finance,
        MerchantUserRole::FrontOffice,
        MerchantUserRole::Personnel,
        MerchantUserRole::Audit,
    ] as $role) {
        [$user] = branchStaff($scn['merchant'], $branch, $role);

        test()->actingAs($user, 'sanctum')->getJson(MP_URL)
            ->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        test()->actingAs($user, 'sanctum')->patchJson(MP_URL, ['town' => 'Kisumu'])
            ->assertForbidden();
    }

    expect($scn['profile']->refresh()->town)->toBe('Nairobi');
});

it('never lets one merchant read or write another merchant profile', function (): void {
    $scn = mpScenario();
    $other = mpScenario();

    // The route takes NO merchant identifier — the tenant is resolved from the membership — so the
    // only reachable profile is the caller's own. Prove each admin sees exactly its own.
    test()->actingAs($scn['admin'], 'sanctum')->getJson(MP_URL)
        ->assertOk()->assertJsonPath('data.id', $scn['profile']->ulid);
    test()->actingAs($other['admin'], 'sanctum')->getJson(MP_URL)
        ->assertOk()->assertJsonPath('data.id', $other['profile']->ulid);

    test()->actingAs($other['admin'], 'sanctum')->patchJson(MP_URL, ['town' => 'Nakuru'])->assertOk();

    expect($scn['profile']->refresh()->town)->toBe('Nairobi')      // untouched
        ->and($other['profile']->refresh()->town)->toBe('Nakuru');
});

it('keeps the read available but blocks the update while billing is read-only', function (): void {
    $scn = mpScenario();

    foreach ([MerchantBillingStatus::ReadOnlyGrace, MerchantBillingStatus::SuspendedBilling] as $status) {
        $scn['merchant']->forceFill(['billing_status' => $status->value])->save();

        // matrix: merchant.profile.view = allow_read
        test()->actingAs($scn['admin'], 'sanctum')->getJson(MP_URL)->assertOk();
        // matrix: merchant.profile.update = block → EnsureBillingMutable 403 billing_read_only
        test()->actingAs($scn['admin'], 'sanctum')->patchJson(MP_URL, ['town' => 'Eldoret'])
            ->assertForbidden()->assertJsonPath('error.code', 'billing_read_only');
    }

    expect($scn['profile']->refresh()->town)->toBe('Nairobi');
});

it('exposes the current logo as a file id and filename only — never a path or URL', function (): void {
    Storage::fake((string) config('files.disk'));
    $scn = mpScenario();

    test()->actingAs($scn['admin'], 'sanctum')->getJson(MP_URL)
        ->assertOk()->assertJsonPath('data.logo', null);

    $logo = availableFile($scn['merchant']->id, FilePurpose::MerchantLogo);

    $body = test()->actingAs($scn['admin'], 'sanctum')->getJson(MP_URL)->assertOk()->json();

    expect($body['data']['logo']['id'])->toBe($logo->ulid)
        ->and(array_keys($body['data']['logo']))->toEqualCanonicalizing(['id', 'filename']);
    expect(json_encode($body))->not->toContain('signature=')->not->toContain('generated/');
});

it('never surfaces another merchant logo on this profile', function (): void {
    Storage::fake((string) config('files.disk'));
    $scn = mpScenario();
    $other = mpScenario();

    availableFile($other['merchant']->id, FilePurpose::MerchantLogo);

    test()->actingAs($scn['admin'], 'sanctum')->getJson(MP_URL)
        ->assertOk()->assertJsonPath('data.logo', null);
});

it('gates the merchant_logo upload on the canonical write key after the legacy retirement', function (): void {
    Storage::fake((string) config('files.disk'));
    $scn = mpScenario();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);

    // The purpose moved from the retired `merchant.profile.manage` to `merchant.profile.update`.
    // The Merchant Administrator holds it; a Front Office user does not.
    $logo = availableFile($scn['merchant']->id, FilePurpose::MerchantLogo);

    test()->actingAs($scn['admin'], 'sanctum')
        ->postJson("/api/v1/files/{$logo->ulid}/download-link")->assertOk();

    [$frontOffice] = branchStaff($scn['merchant'], $branch, MerchantUserRole::FrontOffice);
    test()->actingAs($frontOffice, 'sanctum')
        ->postJson("/api/v1/files/{$logo->ulid}/download-link")
        ->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
});
