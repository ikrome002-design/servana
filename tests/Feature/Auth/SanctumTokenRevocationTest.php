<?php

declare(strict_types=1);

use App\Domain\Auth\Services\AccessRevocationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

uses(RefreshDatabase::class)->group('auth', 'security');

/*
 | Sanctum token revocation (Plan §79 R6). The application is Sanctum SPA-mode
 | only — there is NO personal-access-token issuance surface — so the table is
 | normally empty. We prove that absence AND prove the revocation service clears
 | the table where rows ever exist, without inventing an issuance API.
 */

it('exposes no personal-access-token issuance surface on the user model', function (): void {
    // No HasApiTokens trait and no createToken() — tokens are never issued.
    expect(in_array(HasApiTokens::class, class_uses_recursive(User::class), true))->toBeFalse()
        ->and(method_exists(User::class, 'createToken'))->toBeFalse();
});

it('revokes personal-access tokens for a user where present', function (): void {
    $user = User::factory()->create();

    DB::table('personal_access_tokens')->insert([
        ['tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'a', 'token' => hash('sha256', 'a'), 'created_at' => now(), 'updated_at' => now()],
        ['tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'b', 'token' => hash('sha256', 'b'), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $summary = app(AccessRevocationService::class)->revokeForUser($user);

    expect($summary->tokensRevoked)->toBe(2)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
});

it('never revokes another user\'s tokens', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    DB::table('personal_access_tokens')->insert([
        ['tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'mine', 'token' => hash('sha256', 'mine'), 'created_at' => now(), 'updated_at' => now()],
        ['tokenable_type' => User::class, 'tokenable_id' => $other->id, 'name' => 'theirs', 'token' => hash('sha256', 'theirs'), 'created_at' => now(), 'updated_at' => now()],
    ]);

    app(AccessRevocationService::class)->revokeForUser($user);

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $other->id)->count())->toBe(1);
});

it('is idempotent for token revocation', function (): void {
    $user = User::factory()->create();
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'a', 'token' => hash('sha256', 'a'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $service = app(AccessRevocationService::class);
    $first = $service->revokeForUser($user);
    $second = $service->revokeForUser($user);

    expect($first->tokensRevoked)->toBe(1)
        ->and($second->tokensRevoked)->toBe(0);
});
