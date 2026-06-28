<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('catalogue');

/*
 | Service catalogue (Plan §39; Phase 15A). Branch Manager owns it; Front Office,
 | Merchant Admin and HR cannot mutate it. Tenant/branch isolation, money
 | invariants, archival exclusion, and audit emission are all proven.
 */

function bmBranch(): array
{
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$bm] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    return [$bm, $merchant, $branch];
}

it('lets a Branch Manager create, update, list and archive a service', function (): void {
    [$bm, $merchant, $branch] = bmBranch();
    $category = ServiceCategory::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    $created = $this->actingAs($bm, 'sanctum')->postJson('/api/v1/services', [
        'category_id' => $category->ulid,
        'name' => 'Gel manicure',
        'price_minor' => 250000,
        'currency' => 'KES',
        'duration_minutes' => 45,
    ])->assertCreated()->json('data');

    expect($created['price_minor'])->toBe(250000)
        ->and($created['currency'])->toBe('KES')
        ->and($created['status'])->toBe('active');

    $serviceUlid = $created['id'];

    $this->actingAs($bm, 'sanctum')->patchJson("/api/v1/services/{$serviceUlid}", ['price_minor' => 300000])
        ->assertOk()->assertJsonPath('data.price_minor', 300000);

    $this->actingAs($bm, 'sanctum')->getJson('/api/v1/services')
        ->assertOk()->assertJsonPath('data.0.id', $serviceUlid);

    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/services/{$serviceUlid}/archive")
        ->assertOk()->assertJsonPath('data.status', 'archived');
});

it('rejects archiving an already-archived service with invalid_state_transition', function (): void {
    [$bm, $merchant, $branch] = bmBranch();
    $service = Service::factory()->archived()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/services/{$service->ulid}/archive")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');
});

it('excludes archived services from the active filter', function (): void {
    [$bm, $merchant, $branch] = bmBranch();
    $category = ServiceCategory::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);
    Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id, 'category_id' => $category->id]);
    Service::factory()->archived()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id, 'category_id' => $category->id]);

    $active = $this->actingAs($bm, 'sanctum')->getJson('/api/v1/services?status=active')->json('data');

    expect($active)->toHaveCount(1)->and($active[0]['status'])->toBe('active');
});

it('enforces integer minor-unit money and currency validation', function (): void {
    [$bm, $merchant, $branch] = bmBranch();
    $category = ServiceCategory::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    $this->actingAs($bm, 'sanctum')->postJson('/api/v1/services', [
        'category_id' => $category->ulid,
        'name' => 'Bad money',
        'price_minor' => 250.5, // not an integer
        'currency' => 'EURO', // not ISO-4217
        'duration_minutes' => 0, // must be > 0
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['price_minor', 'currency', 'duration_minutes']]]);
});

it('forbids Front Office from mutating the catalogue', function (): void {
    [, $merchant, $branch] = bmBranch();
    [$fo] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);
    $category = ServiceCategory::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    $this->actingAs($fo, 'sanctum')->postJson('/api/v1/services', [
        'category_id' => $category->ulid,
        'name' => 'X',
        'price_minor' => 1000,
        'duration_minutes' => 30,
    ])->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
});

it('forbids HR from mutating service pricing', function (): void {
    [, $merchant, $branch] = bmBranch();
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    $service = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    $this->actingAs($hr, 'sanctum')->patchJson("/api/v1/services/{$service->ulid}", ['price_minor' => 999])
        ->assertStatus(403);
});

it('forbids Merchant Admin from mutating the catalogue', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $category = ServiceCategory::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/services', [
        'category_id' => $category->ulid,
        'name' => 'X',
        'price_minor' => 1000,
        'duration_minutes' => 30,
    ])->assertStatus(403);
});

it('404s a foreign-tenant service (no existence leak)', function (): void {
    [$bm] = bmBranch();
    [, $otherMerchant] = activeAdmin();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $otherMerchant->id]);
    $foreign = Service::factory()->create(['merchant_id' => $otherMerchant->id, 'branch_id' => $otherBranch->id]);

    $this->actingAs($bm, 'sanctum')->getJson("/api/v1/services/{$foreign->ulid}")->assertStatus(404);
    $this->actingAs($bm, 'sanctum')->patchJson("/api/v1/services/{$foreign->ulid}", ['name' => 'Y'])->assertStatus(404);
});

it('emits an audit event for each catalogue mutation', function (): void {
    [$bm, $merchant, $branch] = bmBranch();
    $category = ServiceCategory::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    $ulid = $this->actingAs($bm, 'sanctum')->postJson('/api/v1/services', [
        'category_id' => $category->ulid,
        'name' => 'Audited',
        'price_minor' => 5000,
        'duration_minutes' => 30,
    ])->json('data.id');

    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/services/{$ulid}/archive")->assertOk();

    expect(AuditLog::query()->where('action', 'service.created')->where('merchant_id', $merchant->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'service.archived')->exists())->toBeTrue();
});
