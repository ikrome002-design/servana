<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Sessions\Enums\HandoffRejectionReason;
use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Models\AccountContextHandoff;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Models\SessionFamily;
use App\Domain\Tenancy\TenantOwnership;
use App\Http\Hosts\AccountHostRegistry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('sessions', 'ui03', 'ui03-schema', 'security');

/*
 |==============================================================================
 | Phase UI-03 schema + database-guard proof (ADR-018, ADR-019; UI/UX plan §5.1–§5.2).
 | Runs on PostgreSQL 16. Proves the tables exist with their canonical constraints, that the two
 | standing invariants hold (no permission snapshot, no raw credential), and that the application
 | enum vocabularies and the DB CHECK vocabularies cannot drift apart.
 |
 | Every throwing statement is the LAST DB write in its test — a failed statement aborts the
 | RefreshDatabase transaction (repo convention, cf. Phase21RASchemaTest).
 */

/** @return list<string> the values allowed by a named CHECK constraint, read from PostgreSQL. */
function ui03CheckVocabulary(string $constraint): array
{
    $definition = (string) DB::selectOne(
        'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
        [$constraint],
    )?->def;

    preg_match_all("/'([a-z_]+)'::/", $definition, $matches);

    $values = array_values(array_unique($matches[1]));
    sort($values);

    return $values;
}

it('creates the three Phase UI-03 tables', function (): void {
    expect(Schema::hasTable('session_families'))->toBeTrue();
    expect(Schema::hasTable('host_sessions'))->toBeTrue();
    expect(Schema::hasTable('account_context_handoffs'))->toBeTrue();
});

it('adds every ADR-019 binding column to magic_login_tokens without touching the shipped ones', function (): void {
    $columns = Schema::getColumnListing('magic_login_tokens');

    // Added by UI-03.
    foreach (['user_id', 'account_key', 'intended_host', 'environment', 'redirect_path', 'audience'] as $added) {
        expect($columns)->toContain($added);
    }

    // Phase 5 guarantees, preserved verbatim (ADR-019 "adds binding; removes nothing").
    foreach (['email', 'token_hash', 'expires_at', 'consumed_at', 'invalidated_at'] as $shipped) {
        expect($columns)->toContain($shipped);
    }
});

it('stores no permission snapshot anywhere in the session or handoff schema', function (): void {
    // The single most important structural invariant of ADR-018: the target host must re-resolve
    // authority, so there must be nowhere to cache it.
    $offenders = [];

    foreach (['session_families', 'host_sessions', 'account_context_handoffs', 'magic_login_tokens'] as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            if (preg_match('/permission|grant|capabilit|abilit|role_keys|scopes/i', $column) === 1) {
                $offenders[] = "{$table}.{$column}";
            }
        }
    }

    expect($offenders)->toBe([], 'Permission-shaped columns found: '.implode(', ', $offenders));
});

it('classifies all three tables as EXEMPT with a written rationale', function (): void {
    foreach (['session_families', 'host_sessions', 'account_context_handoffs'] as $table) {
        expect(TenantOwnership::EXEMPT)->toHaveKey($table);
        expect(TenantOwnership::EXEMPT[$table])->not->toBe('');
        expect(TenantOwnership::TENANT_OWNED)->not->toContain($table);
        expect(TenantOwnership::BRANCH_OWNED)->not->toContain($table);
    }
});

it('keeps the account-key CHECK vocabularies identical to the UI-02 host registry', function (): void {
    $registryKeys = app(AccountHostRegistry::class)->accountKeys();
    sort($registryKeys);

    // A CHECK that drifted from the registry would let a session exist for an account that has no
    // host — or block one that does. The registry stays the single authority (no second registry).
    expect(ui03CheckVocabulary('host_sessions_account_key_check'))->toBe($registryKeys);
    expect(ui03CheckVocabulary('magic_login_tokens_account_key_check'))->toBe($registryKeys);
    expect(ui03CheckVocabulary('account_context_handoffs_source_account_check'))->toBe($registryKeys);
    expect(ui03CheckVocabulary('account_context_handoffs_target_account_check'))->toBe($registryKeys);
});

it('keeps the revocation-reason CHECK identical to the SessionRevocationReason enum', function (): void {
    $enum = SessionRevocationReason::values();
    sort($enum);

    expect(ui03CheckVocabulary('session_families_revoked_reason_check'))->toBe($enum);
    expect(ui03CheckVocabulary('host_sessions_revoked_reason_check'))->toBe($enum);
});

it('keeps the handoff rejection CHECK identical to the HandoffRejectionReason enum', function (): void {
    $enum = HandoffRejectionReason::values();
    sort($enum);

    expect(ui03CheckVocabulary('account_context_handoffs_invalidated_reason_check'))->toBe($enum);
});

it('rejects a usable Magic Link row that is not fully bound', function (): void {
    $user = User::factory()->create();

    // A bound row is accepted.
    MagicLoginToken::query()->create([
        'ulid' => (string) Str::ulid(),
        'email' => $user->email,
        'token_hash' => hash('sha256', Str::random(64)),
        'expires_at' => now()->addMinutes(15),
        'user_id' => $user->id,
        'account_key' => 'merchant_administrator',
        'intended_host' => 'servana.test',
        'environment' => 'testing',
        'audience' => 'browser_login',
    ]);

    // An UNBOUND, still-usable row is refused by the database, not merely by the application.
    expect(fn () => DB::table('magic_login_tokens')->insert([
        'ulid' => (string) Str::ulid(),
        'email' => 'unbound@salon.co.ke',
        'token_hash' => hash('sha256', Str::random(64)),
        'expires_at' => now()->addMinutes(15),
    ]))->toThrow(QueryException::class);
});

it('refuses a merchant-side host session with no merchant', function (): void {
    // The platform account is the only one allowed a null merchant.
    HostSession::factory()->create();

    $family = SessionFamily::factory()->create();

    expect(fn () => HostSession::factory()->create([
        'session_family_id' => $family->id,
        'user_id' => $family->user_id,
        'account_key' => 'merchant_finance',
        'host' => 'finance.servana.test',
        'merchant_id' => null,
    ]))->toThrow(QueryException::class);
});

it('refuses a platform host session that names a merchant', function (): void {
    $family = SessionFamily::factory()->create();
    $merchant = Merchant::factory()->active()->create();

    expect(fn () => HostSession::factory()->create([
        'session_family_id' => $family->id,
        'user_id' => $family->user_id,
        'account_key' => 'super_administrator',
        'merchant_id' => $merchant->id,
    ]))->toThrow(QueryException::class);
});

it('refuses a half-stated revocation', function (): void {
    $family = SessionFamily::factory()->create();

    expect(fn () => DB::table('session_families')
        ->where('id', $family->id)
        ->update(['revoked_at' => now()]))->toThrow(QueryException::class);
});

it('refuses a handoff that is both consumed and invalidated', function (): void {
    $handoff = AccountContextHandoff::factory()->create();

    expect(fn () => DB::table('account_context_handoffs')
        ->where('id', $handoff->id)
        ->update([
            'consumed_at' => now(),
            'invalidated_at' => now(),
            'invalidated_reason' => HandoffRejectionReason::Replayed->value,
        ]))->toThrow(QueryException::class);
});

it('refuses a handoff whose expiry is not in the future of its creation', function (): void {
    expect(fn () => DB::table('account_context_handoffs')->insert([
        'ulid' => (string) Str::ulid(),
        'token_hash' => hash('sha256', Str::random(64)),
        'user_id' => User::factory()->create()->id,
        'source_session_family_id' => SessionFamily::factory()->create()->id,
        'source_account_key' => 'merchant_personnel',
        'target_account_key' => 'super_administrator',
        'target_host' => 'citrus.servana.test',
        'environment' => 'testing',
        'created_at' => now(),
        'expires_at' => now()->subSecond(),
    ]))->toThrow(QueryException::class);
});

it('enforces one handoff row per token hash', function (): void {
    $hash = hash('sha256', Str::random(64));

    AccountContextHandoff::factory()->create(['token_hash' => $hash]);

    expect(fn () => AccountContextHandoff::factory()->create(['token_hash' => $hash]))
        ->toThrow(QueryException::class);
});

it('enforces one host-session row per Laravel session id', function (): void {
    $sessionId = Str::random(40);

    HostSession::factory()->create(['session_id' => $sessionId]);

    expect(fn () => HostSession::factory()->create(['session_id' => $sessionId]))
        ->toThrow(QueryException::class);
});

it('hides the raw session id and token hash from serialization', function (): void {
    $hostSession = HostSession::factory()->create();
    $handoff = AccountContextHandoff::factory()->create();

    expect($hostSession->toArray())->not->toHaveKey('session_id');
    expect($handoff->toArray())->not->toHaveKey('token_hash');
});

it('creates no table that belongs to a later phase', function (): void {
    // UI-03 owns authentication and switching only; anything below would be scope creep.
    foreach ([
        'account_switch_audits', 'session_permissions', 'host_session_permissions',
        'account_context_permissions', 'sso_assertions', 'webauthn_credentials',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} must not exist after Phase UI-03");
    }
});
