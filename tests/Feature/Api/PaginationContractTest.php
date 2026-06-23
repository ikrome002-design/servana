<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('api', 'pagination');

/*
 | Pagination contract (Plan §23, §24.2; Phase 10). Exercised on the retrofitted
 | branch listing, which consumes the shared App\Http\Api\ApiPagination substrate:
 | default 25, max 100, over-limit rejected (422), stable, tenant-isolated.
 */

it('defaults to a page size of 25', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->count(30)->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('meta.total', 30);
});

it('honours an explicit in-range page size', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->count(5)->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 3);
});

it('accepts the maximum page size of 100', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->count(3)->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

it('rejects a page size over the maximum with 422', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches?per_page=101')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a non-positive page size with 422', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches?per_page=0')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('returns an empty, well-formed page when there are no rows', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

it('isolates the paginated total to the caller merchant', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->count(3)->create(['merchant_id' => $merchant->id]);
    // Foreign-merchant rows must never enter the count or the page.
    MerchantBranch::factory()->count(4)->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3);
});

it('exposes only ULID public identifiers in paginated output (no internal ids)', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/branches')->assertOk();

    // The public id is the 26-char ULID, never the internal bigint primary key.
    expect($response->json('data.0.id'))
        ->toBe($branch->ulid)
        ->toHaveLength(26)
        ->not->toBe((string) $branch->id);
});
