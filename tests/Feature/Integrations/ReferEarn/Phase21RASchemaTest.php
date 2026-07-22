<?php

declare(strict_types=1);

use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryResponseClass;
use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReferralCaptureChannel;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Integrations\ReferEarn\Models\ReEventDelivery;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('referearn', 'phase21ra', 'phase21ra-schema');

/*
 | Phase 21R-A schema + database-guard proof (Plan §13.17, §58A, §25.6; ADR-002/004/013/015).
 | Runs on PostgreSQL 16. Proves the three tables exist with their canonical constraints, the
 | data-minimization boundary (no referrer identity column anywhere), the append-only/immutability
 | triggers, and that NO Phase 21R-B or Wallet table was created. Every throwing statement is the
 | LAST DB write in its test (a failed statement aborts the RefreshDatabase transaction).
 */

/** @return list<string> */
function columnsOf(string $table): array
{
    return Schema::getColumnListing($table);
}

it('creates the three Phase 21R-A tables', function (): void {
    expect(Schema::hasTable('referral_snapshots'))->toBeTrue();
    expect(Schema::hasTable('re_outbound_events'))->toBeTrue();
    expect(Schema::hasTable('re_event_deliveries'))->toBeTrue();
});

it('creates NO Phase 21R-B, R&E-platform or Wallet table', function (): void {
    $forbidden = [
        // Phase 21R-B
        're_activity_rule_versions', 're_qualification_periods', 're_qualification_decisions',
        're_inbound_requests',
        // R&E platform-owned (never Servana)
        'reward_ledgers', 'referrer_accounts', 'referrer_payouts', 'referrer_statements',
        'referral_campaigns', 'referral_codes', 'reward_rules',
        // Phase 20D-W (Gate W closed)
        'subscription_payments', 'subscription_payment_reversals', 'wallet_webhook_inbox',
        'billing_reconciliation_exceptions', 'merchant_wallet_accounts', 'subscription_payment_attempts',
    ];

    foreach ($forbidden as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} must not exist after Phase 21R-A");
    }
});

it('classifies all three tables as EXEMPT with a documented rationale', function (): void {
    foreach (['referral_snapshots', 're_outbound_events', 're_event_deliveries'] as $table) {
        expect(TenantOwnership::EXEMPT)->toHaveKey($table);
        expect(TenantOwnership::EXEMPT[$table])->not->toBe('');
        expect(TenantOwnership::TENANT_OWNED)->not->toContain($table);
        expect(TenantOwnership::BRANCH_OWNED)->not->toContain($table);
    }
});

it('gives referral_snapshots a NOT NULL, unique, indexed merchant_id', function (): void {
    $nullable = DB::selectOne(
        'SELECT is_nullable FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
        ['public', 'referral_snapshots', 'merchant_id'],
    );

    expect($nullable?->is_nullable)->toBe('NO');

    $hasUniqueMerchantIndex = collect(DB::select('SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND tablename = ?', ['public', 'referral_snapshots']))
        ->contains(fn (object $i): bool => str_contains((string) $i->indexdef, 'UNIQUE') && str_contains((string) $i->indexdef, '(merchant_id)'));

    expect($hasUniqueMerchantIndex)->toBeTrue('referral_snapshots.merchant_id must be UNIQUE (one snapshot per merchant)');
});

it('stores NO referrer identity anywhere in the R&E schema', function (): void {
    // Plan §9 rule 23 / §13.17: Servana holds only the code and R&E's opaque public attribution id.
    $banned = ['referrer', 'reward', 'payout', 'campaign', 'commission', 'earning', 'balance', 'msisdn', 'phone', 'email'];

    foreach (['referral_snapshots', 're_outbound_events', 're_event_deliveries'] as $table) {
        foreach (columnsOf($table) as $column) {
            foreach ($banned as $needle) {
                expect(str_contains($column, $needle))
                    ->toBeFalse("{$table}.{$column} looks like partner-owned or PII data ({$needle})");
            }
        }
    }
});

it('accepts a valid factory row for each Phase 21R-A model', function (): void {
    expect(ReferralSnapshot::factory()->create())->not->toBeNull();
    expect(ReOutboundEvent::factory()->create())->not->toBeNull();
    expect(ReEventDelivery::factory()->create())->not->toBeNull();
});

it('encrypts the raw referral code at rest and hides it from serialization', function (): void {
    $snapshot = ReferralSnapshot::factory()->create(['raw_code_encrypted' => 'SERVANA-PLAIN']);

    $stored = (string) DB::table('referral_snapshots')->where('id', $snapshot->id)->value('raw_code_encrypted');

    expect($stored)->not->toContain('SERVANA-PLAIN')
        ->and($stored)->not->toBe('')
        ->and($snapshot->fresh()?->raw_code_encrypted)->toBe('SERVANA-PLAIN')
        ->and(array_keys($snapshot->fresh()?->toArray() ?? []))->not->toContain('raw_code_encrypted');
});

it('allows at most one referral snapshot per merchant', function (): void {
    $merchant = Merchant::factory()->create();
    ReferralSnapshot::factory()->create(['merchant_id' => $merchant->id]);

    expect(fn () => ReferralSnapshot::factory()->create(['merchant_id' => $merchant->id]))
        ->toThrow(QueryException::class);
});

it('enforces the referral snapshot CHECK constraints', function (string $column, mixed $value): void {
    $snapshot = ReferralSnapshot::factory()->create();

    expect(fn () => DB::table('referral_snapshots')->where('id', $snapshot->id)->update([$column => $value]))
        ->toThrow(QueryException::class);
})->with([
    'unknown capture channel' => ['capture_channel', 'billboard'],
    'unknown snapshot status' => ['snapshot_status', 'pending_review'],
]);

it('requires a null normalized code exactly when the format is invalid', function (): void {
    // A captured (valid-format) snapshot may not drop its normalized code.
    $snapshot = ReferralSnapshot::factory()->create();

    expect(fn () => DB::table('referral_snapshots')->where('id', $snapshot->id)->update(['code_normalized' => null]))
        ->toThrow(QueryException::class);
});

it('accepts an invalid_format snapshot only with a null normalized code', function (): void {
    $ok = ReferralSnapshot::factory()->invalidFormat()->create();

    expect($ok->snapshot_status)->toBe(ReferralSnapshotStatus::InvalidFormat)
        ->and($ok->code_normalized)->toBeNull();

    expect(fn () => ReferralSnapshot::factory()->invalidFormat()->create(['code_normalized' => 'SERVANA-ABCDE']))
        ->toThrow(QueryException::class);
});

it('freezes referral snapshot capture evidence by trigger', function (): void {
    $snapshot = ReferralSnapshot::factory()->create();

    expect(fn () => DB::table('referral_snapshots')->where('id', $snapshot->id)->update(['captured_at' => now()->subDay()]))
        ->toThrow(QueryException::class, 'capture evidence is immutable');
});

it('blocks any status change out of a terminal referral snapshot state', function (): void {
    $snapshot = ReferralSnapshot::factory()->confirmed()->create();

    expect(fn () => DB::table('referral_snapshots')->where('id', $snapshot->id)->update(['snapshot_status' => 'validating']))
        ->toThrow(QueryException::class);
});

it('enforces the outbox event-type CHECK against Phase 21R-B types', function (): void {
    $event = ReOutboundEvent::factory()->create();

    // subscription.* is a Phase 21R-B catalogue row; the database must refuse it.
    expect(fn () => DB::table('re_outbound_events')->where('id', $event->id)->update(['event_type' => 'subscription.invoice_issued']))
        ->toThrow(QueryException::class);
});

it('keeps the outbox event payload and identity append-only', function (): void {
    $event = ReOutboundEvent::factory()->create();

    expect(fn () => DB::table('re_outbound_events')->where('id', $event->id)->update(['payload' => json_encode(['tampered' => true])]))
        ->toThrow(QueryException::class, 'append-only');
});

it('never deletes an emitted outbox event', function (): void {
    $event = ReOutboundEvent::factory()->create();

    expect(fn () => DB::table('re_outbound_events')->where('id', $event->id)->delete())
        ->toThrow(QueryException::class, 'never deleted');
});

it('keeps sequence_no unique per merchant', function (): void {
    $merchant = Merchant::factory()->create();
    ReOutboundEvent::factory()->create(['merchant_id' => $merchant->id, 'merchant_public_id' => $merchant->ulid, 'sequence_no' => 1]);

    expect(fn () => ReOutboundEvent::factory()->create([
        'merchant_id' => $merchant->id,
        'merchant_public_id' => $merchant->ulid,
        'sequence_no' => 1,
    ]))->toThrow(QueryException::class);
});

it('keeps delivery attempt history append-only', function (): void {
    $delivery = ReEventDelivery::factory()->create();

    expect(fn () => DB::table('re_event_deliveries')->where('id', $delivery->id)->update(['response_status' => 500]))
        ->toThrow(QueryException::class, 'append-only');
});

it('bounds the stored delivery response body to 512 characters', function (): void {
    $length = DB::selectOne(
        'SELECT character_maximum_length AS len FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
        ['public', 're_event_deliveries', 'response_body_truncated_redacted'],
    );

    expect((int) ($length?->len ?? 0))->toBe(512);
});

it('keeps every enum in parity with its database CHECK', function (string $table, string $column, array $expected): void {
    $definition = (string) DB::selectOne(
        'SELECT pg_get_constraintdef(con.oid) AS def
         FROM pg_constraint con JOIN pg_class rel ON rel.oid = con.conrelid
         WHERE rel.relname = ? AND con.contype = ? AND pg_get_constraintdef(con.oid) LIKE ?',
        [$table, 'c', '%'.$column.'%'],
    )?->def;

    foreach ($expected as $value) {
        expect($definition)->toContain("'{$value}'");
    }

    // Count the quoted literals so an EXTRA database value cannot hide behind a passing subset.
    preg_match_all("/'([a-z_.]+)'/", $definition, $matches);
    expect(count(array_unique($matches[1])))->toBe(count($expected));
})->with([
    'snapshot status' => ['referral_snapshots', 'snapshot_status', ReferralSnapshotStatus::values()],
    'capture channel' => ['referral_snapshots', 'capture_channel', ReferralCaptureChannel::values()],
    'delivery status' => ['re_outbound_events', 'delivery_status', ReDeliveryStatus::values()],
    'event type' => ['re_outbound_events', 'event_type', ReOutboundEventType::values()],
    'response class' => ['re_event_deliveries', 'response_class', ReDeliveryResponseClass::values()],
]);
