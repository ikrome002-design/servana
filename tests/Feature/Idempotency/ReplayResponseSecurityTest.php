<?php

declare(strict_types=1);

use App\Domain\Idempotency\Models\IdempotencyKey;
use App\Domain\Idempotency\Support\ReplayResponseSanitizer;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('idempotency', 'security');

/*
 | Storage/replay security (Plan §9 rules 12-13, §24.4-24.5; Phase R4). Raw key
 | never stored; key hashed; body encrypted at rest; unsafe headers never stored
 | or replayed; server failures store no detail.
 */

beforeEach(function (): void {
    Cache::flush();
});

it('stores only the SHA-256 of the key, never the raw key', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);
    $key = 'idem-key-rawkey-test01'; // gitleaks:allow (test idempotency key, not a secret)

    $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 1])->assertStatus(200);

    $stored = DB::table('idempotency_keys')->first();
    $dump = (string) json_encode(DB::table('idempotency_keys')->get());

    expect($stored->key_hash)->toBe(hash('sha256', $key))
        ->and($dump)->not->toContain($key);
});

it('encrypts the response body at rest', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);

    $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => 'idem-encbody-000001'])
        ->postJson('/api/v1/testing/idempotency/financial', ['amount' => 1])->assertStatus(200);

    $rawBody = (string) DB::table('idempotency_keys')->value('response_body_encrypted');

    // Ciphertext must not contain the plaintext payload key.
    expect($rawBody)->not->toContain('count')
        ->and(IdempotencyKey::query()->first()->response_body_encrypted)->toBe(['count' => 1]);
});

it('never stores or replays unsafe headers', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);
    $key = 'idem-key-unsafe-test01'; // gitleaks:allow (test idempotency key, not a secret)

    $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/unsafe-headers', ['amount' => 1])->assertStatus(200);

    $headers = IdempotencyKey::query()->first()->response_headers ?? [];
    $lowerKeys = array_map('strtolower', array_keys($headers));

    foreach (ReplayResponseSanitizer::FORBIDDEN_HEADERS as $forbidden) {
        expect($lowerKeys)->not->toContain($forbidden);
    }

    // Replay must not leak the unsafe header VALUES. (The framework legitimately
    // adds its own session Set-Cookie on any stateful response; what must never
    // appear is the route's secret cookie/token values from the original.)
    $replay = $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/testing/idempotency/unsafe-headers', ['amount' => 1])
        ->assertStatus(200);

    $replayHeaders = (string) json_encode($replay->headers->all());

    expect($replay->headers->get('Idempotent-Replay'))->toBe('true')
        ->and($replay->headers->get('Authorization'))->toBeNull()
        ->and($replayHeaders)->not->toContain('secretcookievalue')
        ->and($replayHeaders)->not->toContain('secret-token')
        ->and($replayHeaders)->not->toContain('csrf-secret');
});

it('stores no sensitive detail on a server failure', function (): void {
    [$user] = memberWithRole(MerchantUserRole::FrontOffice);

    $response = $this->actingAs($user, 'sanctum')->withHeaders(['Idempotency-Key' => 'idem-boom-00000001'])
        ->postJson('/api/v1/testing/idempotency/boom', ['amount' => 1]);

    $response->assertStatus(500);

    $row = IdempotencyKey::query()->first();
    $dump = (string) json_encode(DB::table('idempotency_keys')->get());

    // The exception's detail message must never be stored or returned; only a
    // redacted code is kept. (The route_name legitimately contains "boom", so we
    // assert the secret detail string, not the word "boom".)
    expect($row->state->value)->toBe('failed')
        ->and($row->last_error_code)->toBe('server_error')
        ->and($row->response_body_encrypted)->toBeNull()
        ->and($dump)->not->toContain('secret detail should never be stored')
        ->and($response->getContent())->not->toContain('secret detail should never be stored');
});
