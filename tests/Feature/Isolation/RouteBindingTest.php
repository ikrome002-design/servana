<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('isolation', 'tenancy');

/*
 | Parameterized route-binding isolation (Plan §8.2, §8.4). For every model bound
 | by a public ULID, a foreign-tenant ULID must 404 (never 403 — no existence
 | leak). The caller is an active merchant admin of merchant A; each case targets
 | a resource owned by a different merchant B.
 */

/** Build a foreign-merchant resource and return [method, url] for its bound route. */
function foreignBoundResource(string $kind): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    return match ($kind) {
        'branch' => ['GET', "/api/v1/branches/{$branch->ulid}"],
        'staff' => (function () use ($merchant, $branch): array {
            [, , $profile] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

            return ['GET', "/api/v1/staff/{$profile->ulid}"];
        })(),
        'invitation' => (function () use ($merchant, $branch): array {
            $invitation = StaffInvitation::factory()->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
            ]);

            return ['POST', "/api/v1/staff-invitations/{$invitation->ulid}/resend"];
        })(),
        default => throw new InvalidArgumentException($kind),
    };
}

it('404s a foreign-tenant ULID for every bound model', function (string $kind): void {
    [$admin] = activeAdmin();

    [$method, $url] = foreignBoundResource($kind);

    $this->actingAs($admin, 'sanctum')
        ->json($method, $url)
        ->assertStatus(404);
})->with(['branch', 'staff', 'invitation']);

it('404s a syntactically valid but non-existent ULID', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches/01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertStatus(404);
});

it('resolves an own-merchant ULID through scoped binding', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $branch->ulid);
});

it('never exposes internal bigint ids in API responses', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $payload = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(200)
        ->json('data');

    // The public id is the ULID; the bigint primary key is never serialized.
    expect($payload['id'])->toBe($branch->ulid)
        ->and($payload)->not->toHaveKey('merchant_id')
        ->and((string) ($payload['id'] ?? ''))->not->toBe((string) $branch->id);
});
