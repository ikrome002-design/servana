<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Models\Client;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('clients');

/*
 | Client records (Plan §35; guardrail §6.4; Phase 15A). Front Office owns them;
 | contact is encrypted + masked; phone search/duplicate use a blind index that is
 | never returned; search is branch/tenant-isolated; Personnel has no access and
 | no contact-export route; consent persists and is audited.
 */

function foBranch(): array
{
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$fo] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    return [$fo, $merchant, $branch];
}

it('lets Front Office create, view, search and update a client (masked contact)', function (): void {
    [$fo] = foBranch();

    $created = $this->actingAs($fo, 'sanctum')->postJson('/api/v1/clients', [
        'full_name' => 'Amina Yusuf',
        'phone' => '0712345678',
        'email' => 'amina@example.com',
    ])->assertCreated()->json('data');

    // Response is masked only — never the full phone/email or the blind index.
    expect($created)->not->toHaveKey('phone')
        ->and($created)->not->toHaveKey('phone_index')
        ->and($created)->not->toHaveKey('phone_encrypted')
        ->and($created)->not->toHaveKey('email')
        ->and($created['phone_masked'])->toBe('••• ••• 5678')
        ->and($created['phone_last_four'])->toBe('5678')
        ->and($created['email_masked'])->toBe('a••@example.com');

    $ulid = $created['id'];

    $this->actingAs($fo, 'sanctum')->getJson("/api/v1/clients/{$ulid}")
        ->assertOk()->assertJsonPath('data.phone_masked', '••• ••• 5678');

    // Search by name and by normalized phone both resolve, masked.
    $this->actingAs($fo, 'sanctum')->getJson('/api/v1/clients?q=Amina')
        ->assertOk()->assertJsonPath('data.0.id', $ulid);
    $this->actingAs($fo, 'sanctum')->getJson('/api/v1/clients?q=%2B254712345678')
        ->assertOk()->assertJsonPath('data.0.id', $ulid);

    $this->actingAs($fo, 'sanctum')->patchJson("/api/v1/clients/{$ulid}", ['full_name' => 'Amina Y.'])
        ->assertOk()->assertJsonPath('data.full_name', 'Amina Y.');
});

it('rejects a same-branch duplicate phone with a deterministic 409', function (): void {
    [$fo] = foBranch();

    $first = $this->actingAs($fo, 'sanctum')->postJson('/api/v1/clients', [
        'full_name' => 'First', 'phone' => '0712345678',
    ])->assertCreated()->json('data.id');

    $this->actingAs($fo, 'sanctum')->postJson('/api/v1/clients', [
        'full_name' => 'Second', 'phone' => '+254712345678', // same normalized number
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'duplicate_client')
        ->assertJsonPath('error.meta.client_id', $first);
});

it('stores contact as ciphertext and never returns or logs the blind index', function (): void {
    [$fo, $merchant] = foBranch();

    $ulid = $this->actingAs($fo, 'sanctum')->postJson('/api/v1/clients', [
        'full_name' => 'Cipher', 'phone' => '0722000111', 'email' => 'c@example.com',
    ])->assertCreated()->json('data.id');

    $row = DB::table('clients')->where('ulid', $ulid)->first();
    expect($row->phone_encrypted)->not->toContain('254722000111')   // ciphertext, not plaintext
        ->and(strlen($row->phone_index))->toBe(64);                  // HMAC hex present in DB

    // The blind index and full contact never appear in any audit payload.
    $audit = AuditLog::query()->where('action', 'client.created')->where('merchant_id', $merchant->id)->firstOrFail();
    $json = json_encode($audit->getAttributes());
    expect($json)->not->toContain($row->phone_index)
        ->and($json)->not->toContain('254722000111')
        ->and($json)->not->toContain('c@example.com');
});

it('isolates client search by branch and tenant', function (): void {
    [$foA, $merchantA, $branchA] = foBranch();
    [$foB] = foBranch(); // different merchant + branch

    $this->actingAs($foA, 'sanctum')->postJson('/api/v1/clients', ['full_name' => 'Tenant A', 'phone' => '0712345678'])->assertCreated();

    // The same phone exists for A; B searching it finds nothing (no cross-tenant leak).
    $this->actingAs($foB, 'sanctum')->getJson('/api/v1/clients?q=%2B254712345678')
        ->assertOk()->assertJsonCount(0, 'data');

    // The same phone is allowed again in B's own branch (branch-scoped uniqueness).
    $this->actingAs($foB, 'sanctum')->postJson('/api/v1/clients', ['full_name' => 'Tenant B', 'phone' => '0712345678'])
        ->assertCreated();
});

it('denies Personnel any client access and exposes no contact-export route', function (): void {
    [, $merchant, $branch] = foBranch();
    [$personnel] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);
    $client = Client::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    $this->actingAs($personnel, 'sanctum')->getJson('/api/v1/clients')->assertStatus(403);
    $this->actingAs($personnel, 'sanctum')->getJson("/api/v1/clients/{$client->ulid}")->assertStatus(403);

    // No contact-export endpoint exists anywhere (guardrail §6.8).
    $this->actingAs($personnel, 'sanctum')->getJson('/api/v1/clients/export')->assertStatus(404);
    $this->actingAs($personnel, 'sanctum')->postJson("/api/v1/clients/{$client->ulid}/export")->assertStatus(404);
});

it('persists and audits SMS consent opt-in and opt-out', function (): void {
    [$fo, $merchant] = foBranch();
    $ulid = $this->actingAs($fo, 'sanctum')->postJson('/api/v1/clients', ['full_name' => 'Consent', 'phone' => '0712345678'])
        ->json('data.id');

    $this->actingAs($fo, 'sanctum')->putJson("/api/v1/clients/{$ulid}/sms-consent", ['state' => 'opted_in'])
        ->assertOk()->assertJsonPath('data.state', 'opted_in');
    $this->actingAs($fo, 'sanctum')->putJson("/api/v1/clients/{$ulid}/sms-consent", ['state' => 'opted_out'])
        ->assertOk()->assertJsonPath('data.state', 'opted_out');

    // One current state per (client, sms).
    expect(DB::table('client_consents')->where('channel', 'sms')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'client_consent.opted_in')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'client_consent.opted_out')->exists())->toBeTrue();
});

it('404s a foreign-tenant client (no existence leak)', function (): void {
    [$fo] = foBranch();
    [, $otherMerchant] = activeAdmin();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $otherMerchant->id]);
    $foreign = Client::factory()->create(['merchant_id' => $otherMerchant->id, 'branch_id' => $otherBranch->id]);

    $this->actingAs($fo, 'sanctum')->getJson("/api/v1/clients/{$foreign->ulid}")->assertStatus(404);
    $this->actingAs($fo, 'sanctum')->patchJson("/api/v1/clients/{$foreign->ulid}", ['full_name' => 'X'])->assertStatus(404);
});

it('forbids a Branch Manager from creating clients', function (): void {
    [, $merchant, $branch] = foBranch();
    [$bm] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($bm, 'sanctum')->postJson('/api/v1/clients', ['full_name' => 'X', 'phone' => '0712345678'])
        ->assertStatus(403);
});
