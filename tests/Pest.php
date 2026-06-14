<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 | Bind the Laravel TestCase to the Feature and Unit suites so Pest tests get
 | the full application container. Per CLAUDE.md §6.13 the database-backed
 | suites run against PostgreSQL (never SQLite); Phase 1 tests are DB-less.
 */
pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
 | Post to the API as a first-party SPA request. Sending an Origin from a
 | stateful domain makes Sanctum apply the session middleware (StartSession),
 | so endpoints that establish a session (Magic Link verify → login + session
 | regeneration, Plan §9.2) exercise the real stateful path under test.
 *
 * @param  array<string, mixed>  $data
 */
function postStateful(string $uri, array $data = []): TestResponse
{
    return test()
        ->withHeader('Origin', 'http://localhost')
        ->postJson($uri, $data);
}

/*
 | Build a Magic-Link-eligible user (Scope §2.3 checks 2 & 4, enforced from
 | Phase 6): an active user holding an active merchant_admin membership in an
 | active merchant. Phase 5 auth tests use this so they exercise the auth flow
 | against an eligible identity now that tenancy gating is on.
 |
 * @param  array<string, mixed>  $merchantAttributes
 */
function eligibleOwner(string $email, array $merchantAttributes = []): User
{
    $user = User::factory()->create(['email' => $email]);

    $merchant = Merchant::factory()->active()->create($merchantAttributes);

    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    return $user;
}
