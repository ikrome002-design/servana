<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Events\AuditChainVerificationFailed;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('audit', 'security', 'scheduler');

/*
 | Increment 7 (Plan §71): a failing verification run emits exactly ONE bounded,
 | redacted AuditChainVerificationFailed signal. Corruption is simulated only in
 | the isolated test DB (the immutability trigger is never permanently weakened).
 */

function seedFailureChain(int $merchantId, int $count): void
{
    $recorder = app(AuditRecorder::class);
    for ($i = 0; $i < $count; $i++) {
        $recorder->record(AuditEvent::LoginSuccess, null, $merchantId);
    }
}

it('emits no signal when every chain is valid', function (): void {
    Event::fake([AuditChainVerificationFailed::class]);
    $m = Merchant::factory()->active()->create();
    seedFailureChain($m->id, 3);

    expect(Artisan::call('audit:verify-chain'))->toBe(0);
    Event::assertNotDispatched(AuditChainVerificationFailed::class);
});

it('emits exactly one bounded signal on a tampered (hash mismatch) chain', function (): void {
    Event::fake([AuditChainVerificationFailed::class]);
    $m = Merchant::factory()->active()->create();
    seedFailureChain($m->id, 3);

    $row = AuditLog::query()->where('merchant_id', $m->id)->orderBy('id')->skip(1)->first();
    DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_no_update');
    DB::table('audit_logs')->where('id', $row->id)->update(['action' => 'tampered.value']);
    DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_no_update');

    expect(Artisan::call('audit:verify-chain'))->not->toBe(0);

    Event::assertDispatchedTimes(AuditChainVerificationFailed::class, 1);
    Event::assertDispatched(AuditChainVerificationFailed::class, function (AuditChainVerificationFailed $e) use ($m): bool {
        return $e->severity === 'critical'
            && $e->category === AuditChainVerificationFailed::CATEGORY_HASH_MISMATCH
            && $e->chainIdentifier === "merchant:{$m->id}"
            && $e->correlationId !== ''
            && $e->failedChainCount === 1;
    });
});

it('categorises a forged inserted row as a broken link', function (): void {
    Event::fake([AuditChainVerificationFailed::class]);
    $m = Merchant::factory()->active()->create();
    seedFailureChain($m->id, 2);

    DB::table('audit_logs')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $m->id,
        'actor_id' => null,
        'action' => 'login_success',
        'severity' => 'info',
        'context' => json_encode([]),
        'previous_hash' => str_repeat('0', 64),
        'hash' => str_repeat('f', 64),
        'created_at' => now(),
    ]);

    expect(Artisan::call('audit:verify-chain', ['--merchant' => $m->id]))->not->toBe(0);

    Event::assertDispatched(AuditChainVerificationFailed::class, fn (AuditChainVerificationFailed $e): bool => $e->category === AuditChainVerificationFailed::CATEGORY_BROKEN_LINK);
});

it('keeps the signal fully redacted — only safe bounded metadata, no payload/hash/PII', function (): void {
    $captured = null;
    Event::listen(AuditChainVerificationFailed::class, function (AuditChainVerificationFailed $e) use (&$captured): void {
        $captured = $e;
    });

    $m = Merchant::factory()->active()->create();
    seedFailureChain($m->id, 3);
    $row = AuditLog::query()->where('merchant_id', $m->id)->orderBy('id')->skip(1)->first();
    DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_no_update');
    DB::table('audit_logs')->where('id', $row->id)->update(['action' => 'tampered.value']);
    DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_no_update');

    Artisan::call('audit:verify-chain');

    expect($captured)->not->toBeNull();
    $payload = $captured->toArray();

    // Exactly the bounded, safe field set — nothing else can be added silently.
    expect(array_keys($payload))->toBe([
        'severity', 'category', 'chain_identifier', 'correlation_id', 'failed_chain_count', 'occurred_at',
    ]);

    // The chain identifier and category are from fixed allowlists (no free text).
    expect($payload['chain_identifier'])->toMatch('/^(platform|merchant:\d+)$/');
    expect($payload['category'])->toBeIn([
        AuditChainVerificationFailed::CATEGORY_BROKEN_LINK,
        AuditChainVerificationFailed::CATEGORY_HASH_MISMATCH,
    ]);

    // No forbidden content anywhere in the serialized signal.
    $blob = json_encode($payload);
    expect($blob)->not->toContain('tampered.value');           // no audit payload/context
    expect($blob)->not->toMatch('/[0-9a-f]{64}/');             // no full hashes
    expect($blob)->not->toContain('SQLSTATE');                 // no driver error
    expect($blob)->not->toContain($row->ulid);                 // no record identifier
});

it('never lets the verify command mutate an audit row', function (): void {
    $m = Merchant::factory()->active()->create();
    seedFailureChain($m->id, 3);

    $before = AuditLog::query()->orderBy('id')->pluck('hash', 'id')->toArray();
    Artisan::call('audit:verify-chain');
    $after = AuditLog::query()->orderBy('id')->pluck('hash', 'id')->toArray();

    expect($after)->toBe($before);
});
