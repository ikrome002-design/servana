<?php

declare(strict_types=1);

use App\Domain\PlatformAccess\Actions\AcceptPlatformAccessInvitation;
use App\Domain\PlatformAccess\Actions\InvitePlatformAdministrator;
use App\Domain\PlatformAccess\Enums\PlatformAccessInvitationStatus;
use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Exceptions\PlatformAccessException;
use App\Domain\PlatformAccess\Models\PlatformAccessInvitation;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\PlatformAccess\Support\PlatformAccessInvitationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('platform-access', 'ui08', 'ui08-internal-access');

/*
 | COR-UI08-001 §11.6 — invitation security.
 |
 | The token is a bearer credential and is treated as one: random, hashed at rest, single-use,
 | expiring, rotated on resend, purpose- and environment-bound, and enumeration-safe.
 */

function ui08InviteAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);
    PlatformAccessMembership::factory()->create(['user_id' => $user->id]);

    return $user;
}

it('stores only the SHA-256 hash and never the raw token', function (): void {
    $actor = ui08InviteAdmin();

    $result = app(InvitePlatformAdministrator::class)
        ->handle('newadmin@example.com', 'Onboarding a new platform administrator.', $actor, 'testing');

    $raw = (string) $result['raw_token'];
    $invitation = $result['invitation'];

    expect($raw)->not->toBe('')
        ->and(strlen($raw))->toBe(64)
        ->and($invitation->token_hash)->toBe(hash('sha256', $raw));

    // The raw token appears nowhere in the row.
    $row = (array) DB::table('platform_access_invitations')->where('id', $invitation->id)->first();
    foreach ($row as $value) {
        expect(is_string($value) && $value === $raw)->toBeFalse('the raw token was persisted');
    }
});

it('hides the token hash from every serialization', function (): void {
    $invitation = PlatformAccessInvitation::factory()->create();

    expect(array_key_exists('token_hash', $invitation->toArray()))->toBeFalse();
});

it('binds the invitation to a purpose and an environment', function (): void {
    $actor = ui08InviteAdmin();

    $result = app(InvitePlatformAdministrator::class)
        ->handle('bound@example.com', 'Purpose and environment binding.', $actor, 'testing');

    expect($result['invitation']->purpose)->toBe(PlatformAccessInvitation::PURPOSE)
        ->and($result['invitation']->environment)->toBe('testing');

    // A credential minted for one environment cannot be replayed into another.
    $user = User::factory()->create(['email' => 'bound@example.com']);

    expect(fn () => app(AcceptPlatformAccessInvitation::class)->handle((string) $result['raw_token'], $user, 'production'))
        ->toThrow(PlatformAccessException::class);

    expect($result['invitation']->refresh()->status)->toBe(PlatformAccessInvitationStatus::Pending);
});

it('consumes an invitation exactly once', function (): void {
    $actor = ui08InviteAdmin();
    $result = app(InvitePlatformAdministrator::class)
        ->handle('single@example.com', 'Single-use consumption.', $actor, 'testing');

    $raw = (string) $result['raw_token'];
    $user = User::factory()->create(['email' => 'single@example.com']);

    $membership = app(AcceptPlatformAccessInvitation::class)->handle($raw, $user, 'testing');

    expect($membership->status)->toBe(PlatformAccessStatus::Active)
        ->and($user->refresh()->is_platform_staff)->toBeTrue()
        ->and($result['invitation']->refresh()->status)->toBe(PlatformAccessInvitationStatus::Accepted);

    // A replay of the same token is refused.
    expect(fn () => app(AcceptPlatformAccessInvitation::class)->handle($raw, User::factory()->create(), 'testing'))
        ->toThrow(PlatformAccessException::class);
});

it('refuses an expired invitation', function (): void {
    // `expires_at > created_at` is a CHECK, so an expired invitation must have been ISSUED in the
    // past; the factory state backdates both rather than producing an unrepresentable row.
    $raw = Str::random(64);
    $invitation = PlatformAccessInvitation::factory()->expired()->create([
        'token_hash' => PlatformAccessInvitationToken::hash($raw),
    ]);

    expect($invitation->expires_at->isPast())->toBeTrue();

    expect(fn () => app(AcceptPlatformAccessInvitation::class)->handle($raw, User::factory()->create(), 'testing'))
        ->toThrow(PlatformAccessException::class);
});

it('refuses a revoked invitation', function (): void {
    $raw = Str::random(64);
    $invitation = PlatformAccessInvitation::factory()->revoked()->create([
        'token_hash' => PlatformAccessInvitationToken::hash($raw),
    ]);

    expect(fn () => app(AcceptPlatformAccessInvitation::class)->handle($raw, User::factory()->create(), 'testing'))
        ->toThrow(PlatformAccessException::class);

    expect($invitation->refresh()->status)->toBe(PlatformAccessInvitationStatus::Revoked);
});

it('rotates the secret on resend so the previous link stops working', function (): void {
    $admin = ui08InviteAdmin();
    $result = app(InvitePlatformAdministrator::class)
        ->handle('rotate@example.com', 'Rotation on resend.', $admin, 'testing');

    $firstToken = (string) $result['raw_token'];
    $invitation = $result['invitation'];

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/internal-access/invitations/{$invitation->ulid}/resend", ['reason' => 'Resending the invitation.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk();

    $invitation->refresh();

    expect($invitation->resend_count)->toBe(1)
        ->and($invitation->token_hash)->not->toBe(PlatformAccessInvitationToken::hash($firstToken));

    // The FIRST link is now dead — a resend must not widen the window a leaked link stays valid.
    expect(fn () => app(AcceptPlatformAccessInvitation::class)->handle($firstToken, User::factory()->create(), 'testing'))
        ->toThrow(PlatformAccessException::class);
});

it('answers identically whether or not the address is already known', function (): void {
    $admin = ui08InviteAdmin();

    // An address that already holds ACTIVE platform access.
    $existing = PlatformAccessMembership::factory()->create();
    $knownEmail = $existing->user->email;

    $known = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/internal-access/invitations', ['email' => $knownEmail, 'reason' => 'Inviting a known address.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(202);

    $unknown = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/internal-access/invitations', ['email' => 'nobody-here@example.com', 'reason' => 'Inviting an unknown address.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(202);

    // Indistinguishable: same status, same body. Nothing discloses whether the user exists.
    expect($known->json())->toBe($unknown->json())
        ->and($known->status())->toBe($unknown->status());

    // And no invitation was minted for the already-active administrator.
    expect(PlatformAccessInvitation::query()->where('email', $knownEmail)->exists())->toBeFalse();
});

it('permits only one live invitation per address and rotates rather than duplicating', function (): void {
    $admin = ui08InviteAdmin();

    $first = app(InvitePlatformAdministrator::class)->handle('once@example.com', 'First invitation.', $admin, 'testing');
    $second = app(InvitePlatformAdministrator::class)->handle('once@example.com', 'Second invitation.', $admin, 'testing');

    expect(PlatformAccessInvitation::query()->where('email', 'once@example.com')->count())->toBe(1)
        ->and($second['invitation']->id)->toBe($first['invitation']->id)
        ->and($second['invitation']->resend_count)->toBe(1);

    // The first link is dead; only the newest one redeems.
    expect(fn () => app(AcceptPlatformAccessInvitation::class)->handle((string) $first['raw_token'], User::factory()->create(), 'testing'))
        ->toThrow(PlatformAccessException::class);
});

it('normalizes the address so a case-differing duplicate cannot exist', function (): void {
    $admin = ui08InviteAdmin();

    app(InvitePlatformAdministrator::class)->handle('MiXeD@Example.COM', 'Case normalization.', $admin, 'testing');

    expect(PlatformAccessInvitation::query()->where('email', 'mixed@example.com')->exists())->toBeTrue();
});

it('grants platform access only, writing no merchant structure', function (): void {
    $admin = ui08InviteAdmin();
    $result = app(InvitePlatformAdministrator::class)->handle('scoped@example.com', 'Scope of acceptance.', $admin, 'testing');

    $before = [
        'merchant_users' => DB::table('merchant_users')->count(),
        'branch_user_assignments' => DB::table('branch_user_assignments')->count(),
        'staff_profiles' => DB::table('staff_profiles')->count(),
    ];

    app(AcceptPlatformAccessInvitation::class)
        ->handle((string) $result['raw_token'], User::factory()->create(['email' => 'scoped@example.com']), 'testing');

    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count, $table.' was written by invitation acceptance');
    }
});

it('masks the address in audit context', function (): void {
    expect(PlatformAccessInvitationToken::maskEmail('alice@example.com'))->toBe('a***e@example.com')
        ->and(PlatformAccessInvitationToken::maskEmail('ab@example.com'))->toBe('**@example.com');
});
