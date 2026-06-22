<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('audit', 'security');

/*
 | R2: the audit:verify-chain command verifies the per-merchant and platform hash
 | chains using the SAME hasher the recorder uses (Plan §70, ADR-008). Corruption
 | is simulated only here, in the isolated test DB; the production immutability
 | trigger is never permanently weakened.
 */

/** Append n events to a merchant (or platform when null) chain via the recorder. */
function seedChain(?int $merchantId, int $count): void
{
    $recorder = app(AuditRecorder::class);
    $actor = $merchantId !== null ? User::factory()->create() : null;

    for ($i = 0; $i < $count; $i++) {
        $recorder->record(AuditEvent::LoginSuccess, $actor, $merchantId);
    }
}

it('passes for valid platform and merchant chains', function (): void {
    $a = Merchant::factory()->active()->create();
    $b = Merchant::factory()->active()->create();

    seedChain(null, 3);    // platform chain
    seedChain($a->id, 4);  // merchant A
    seedChain($b->id, 2);  // merchant B

    expect(Artisan::call('audit:verify-chain'))->toBe(0);
});

it('keeps merchant chains independent — corrupting one does not fail another', function (): void {
    $a = Merchant::factory()->active()->create();
    $b = Merchant::factory()->active()->create();
    seedChain($a->id, 3);
    seedChain($b->id, 3);

    // Tamper a row in merchant A (disable the trigger only inside this test DB).
    $rowA = AuditLog::query()->where('merchant_id', $a->id)->orderBy('id')->skip(1)->first();
    DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_no_update');
    DB::table('audit_logs')->where('id', $rowA->id)->update(['action' => 'tampered.value']);
    DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_no_update');

    // The whole verification fails, but merchant B's chain alone still verifies.
    expect(Artisan::call('audit:verify-chain'))->not->toBe(0)
        ->and(Artisan::call('audit:verify-chain', ['--merchant' => $b->id]))->toBe(0)
        ->and(Artisan::call('audit:verify-chain', ['--merchant' => $a->id]))->not->toBe(0);
});

it('detects a forged inserted row (broken link)', function (): void {
    $a = Merchant::factory()->active()->create();
    seedChain($a->id, 2);

    // Insert a row directly with a wrong previous_hash — the recorder is bypassed,
    // so the chain link is broken (forgery), even though INSERT is allowed.
    DB::table('audit_logs')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $a->id,
        'actor_id' => null,
        'action' => 'login_success',
        'severity' => 'info',
        'context' => json_encode([]),
        'previous_hash' => str_repeat('0', 64), // wrong link
        'hash' => str_repeat('f', 64),
        'created_at' => now(),
    ]);

    expect(Artisan::call('audit:verify-chain', ['--merchant' => $a->id]))->not->toBe(0);
});

it('does not modify any audit row while verifying', function (): void {
    $a = Merchant::factory()->active()->create();
    seedChain($a->id, 3);
    seedChain(null, 2);

    $before = AuditLog::query()->orderBy('id')->pluck('hash', 'id')->toArray();

    Artisan::call('audit:verify-chain');

    $after = AuditLog::query()->orderBy('id')->pluck('hash', 'id')->toArray();
    expect($after)->toBe($before);
});
