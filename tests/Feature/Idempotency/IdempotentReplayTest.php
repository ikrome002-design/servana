<?php

declare(strict_types=1);

use App\Domain\Idempotency\Models\IdempotencyKey;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class)->group('idempotency');

/*
 | Replay (Plan §24.4 steps 4,6; Phase R4). Same key + same request → exactly one
 | effect; the second response is replayed (Idempotent-Replay) with the original
 | body and creates no new effect.
 */

beforeEach(function (): void {
    Cache::flush();
});

it('executes once and replays the stored response on a duplicate', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);
    $key = 'idem-key-replay-0001';

    $first = $this->actingAs($user, 'sanctum')
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 500])
        ->assertStatus(200)
        ->assertJsonPath('count', 1);

    expect($first->headers->get('Idempotent-Replay'))->toBeNull();

    $second = $this->actingAs($user, 'sanctum')
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 500])
        ->assertStatus(200)
        ->assertJsonPath('count', 1); // replayed — NOT incremented to 2

    expect($second->headers->get('Idempotent-Replay'))->toBe('true')
        ->and(Cache::get('idem_test_effect'))->toBe(1)             // exactly one effect
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('replays a stable 4xx deterministically', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);
    $key = 'idem-key-stable4xx-01';

    $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/stable-failure', ['x' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'demo_validation');

    $replay = $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/stable-failure', ['x' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'demo_validation');

    expect($replay->headers->get('Idempotent-Replay'))->toBe('true')
        ->and(IdempotencyKey::query()->where('state', 'completed')->count())->toBe(1);
});

it('does not collide across different actors or merchants', function (): void {
    [$userA] = memberWithRole(MerchantUserRole::FrontOffice);
    [$userB] = memberWithRole(MerchantUserRole::FrontOffice);
    $key = 'idem-shared-key-00001';

    $this->actingAs($userA, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 1])
        ->assertJsonPath('count', 1);

    // Same raw key, different actor/merchant scope → independent effect, no replay.
    $second = $this->actingAs($userB, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 1])
        ->assertStatus(200);

    expect($second->headers->get('Idempotent-Replay'))->toBeNull()
        ->and(IdempotencyKey::query()->distinct('idempotency_scope')->count('idempotency_scope'))->toBe(2);
});

it('requires an Idempotency-Key header', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');
});

it('rejects a malformed (too short) Idempotency-Key', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);

    $this->actingAs($user, 'sanctum')
        ->withHeaders(['Idempotency-Key' => 'short'])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_idempotency_key');
});
