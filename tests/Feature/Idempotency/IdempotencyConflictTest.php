<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class)->group('idempotency');

/*
 | Conflict (Plan §24.3, §24.4 step 4; Phase R4). The same key with a materially
 | different request → 409 idempotency_key_reused_with_different_request, and the
 | original effect is never re-run.
 */

beforeEach(function (): void {
    Cache::flush();
});

it('409s when the same key is reused with a different body', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);
    $key = 'idem-conflict-key-0001';

    $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 100])
        ->assertStatus(200)
        ->assertJsonPath('count', 1);

    $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 999])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused_with_different_request');

    // No second effect from the conflicting request.
    expect(Cache::get('idem_test_effect'))->toBe(1);
});

it('409s when the same key is reused on a different route', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);
    $key = 'idem-conflict-route-01';

    $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 1])
        ->assertStatus(200);

    $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/unsafe-headers', ['amount' => 1])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused_with_different_request');
});
