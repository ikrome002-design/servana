<?php

declare(strict_types=1);

use App\Domain\Billing\Models\PlatformFeeAdjustment;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-schema');

/*
 | Phase 20E schema + database-guard proof (Plan §13.10, §51; ADR-004/005). Runs on PostgreSQL 16.
 | Proves the four tables exist with the canonical constraints, tenant/ownership registration,
 | financial invariants, append-only immutability at raw SQL, and no historical liability backfill.
 */

it('creates the four Phase 20E tables', function (): void {
    expect(Schema::hasTable('platform_fee_configurations'))->toBeTrue()
        ->and(Schema::hasTable('platform_fee_ledger_entries'))->toBeTrue()
        ->and(Schema::hasTable('platform_fee_adjustments'))->toBeTrue()
        ->and(Schema::hasTable('platform_fee_disputes'))->toBeTrue();
});

it('registers ownership: config platform-exempt, ledger/adjustment/dispute tenant-owned', function (): void {
    expect(TenantOwnership::EXEMPT)->toHaveKey('platform_fee_configurations')
        ->and(TenantOwnership::TENANT_OWNED)->toContain('platform_fee_ledger_entries')
        ->and(TenantOwnership::TENANT_OWNED)->toContain('platform_fee_adjustments')
        ->and(TenantOwnership::TENANT_OWNED)->toContain('platform_fee_disputes')
        ->and(TenantOwnership::MODELS)->toHaveKey(PlatformFeeLedgerEntry::class)
        ->and(TenantOwnership::MODELS[PlatformFeeLedgerEntry::class])->toBe('tenant');
});

it('builds valid rows from every Phase 20E factory', function (): void {
    expect(PlatformFeeConfiguration::factory()->create())->toBeInstanceOf(PlatformFeeConfiguration::class)
        ->and(PlatformFeeConfiguration::factory()->fixedAmount()->create()->tier_behavior)->toBeNull()
        ->and(PlatformFeeConfiguration::factory()->shared()->create()->shared_split_basis_points)->toBe(5000)
        ->and(PlatformFeeLedgerEntry::factory()->create())->toBeInstanceOf(PlatformFeeLedgerEntry::class)
        ->and(PlatformFeeLedgerEntry::factory()->shared()->create())->toBeInstanceOf(PlatformFeeLedgerEntry::class)
        ->and(PlatformFeeLedgerEntry::factory()->businessCentric()->create())->toBeInstanceOf(PlatformFeeLedgerEntry::class)
        ->and(PlatformFeeAdjustment::factory()->create())->toBeInstanceOf(PlatformFeeAdjustment::class)
        ->and(PlatformFeeDispute::factory()->create())->toBeInstanceOf(PlatformFeeDispute::class);
});

// ---- configuration constraints -------------------------------------------------

it('rejects a lowercase currency on the configuration', function (): void {
    PlatformFeeConfiguration::factory()->create(['currency' => 'kes']);
})->throws(QueryException::class);

it('rejects a percentage rate above 10000 bps', function (): void {
    PlatformFeeConfiguration::factory()->create(['percentage_basis_points' => 10001]);
})->throws(QueryException::class);

it('rejects a shared tier with no split', function (): void {
    PlatformFeeConfiguration::factory()->shared()->create(['shared_split_basis_points' => null]);
})->throws(QueryException::class);

it('rejects a non-shared tier that carries a split', function (): void {
    PlatformFeeConfiguration::factory()->customerCentric()->create(['shared_split_basis_points' => 5000]);
})->throws(QueryException::class);

it('rejects a fixed_amount configuration that carries a percentage rate', function (): void {
    PlatformFeeConfiguration::factory()->fixedAmount()->create(['percentage_basis_points' => 250]);
})->throws(QueryException::class);

it('rejects overlapping active configurations for the same mode + currency', function (): void {
    PlatformFeeConfiguration::factory()->active()->create([
        'currency' => 'KES', 'effective_from' => today(), 'effective_to' => null,
    ]);

    PlatformFeeConfiguration::factory()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->addDays(5), 'effective_to' => null,
    ]);
})->throws(QueryException::class);

it('allows adjacent (non-overlapping) active configuration windows', function (): void {
    PlatformFeeConfiguration::factory()->active()->create([
        'currency' => 'KES', 'effective_from' => today(), 'effective_to' => today()->addDays(10),
    ]);

    $second = PlatformFeeConfiguration::factory()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->addDays(10), 'effective_to' => null,
    ]);

    expect($second->exists)->toBeTrue();
});

// ---- ledger invariants ---------------------------------------------------------

it('rejects a ledger entry whose split does not sum to gross', function (): void {
    PlatformFeeLedgerEntry::factory()->create([
        'gross_platform_fee_minor' => 250,
        'client_shifted_amount_minor' => 100,
        'merchant_absorbed_amount_minor' => 100, // 100 + 100 != 250
        'merchant_liability_minor' => 250,
    ]);
})->throws(QueryException::class);

it('rejects a ledger entry whose liability is not the full gross fee', function (): void {
    PlatformFeeLedgerEntry::factory()->create([
        'gross_platform_fee_minor' => 250,
        'client_shifted_amount_minor' => 0,
        'merchant_absorbed_amount_minor' => 250,
        'merchant_liability_minor' => 100, // != gross
    ]);
})->throws(QueryException::class);

it('rejects an earned entry that carries a reversed_entry_id', function (): void {
    $original = PlatformFeeLedgerEntry::factory()->create();

    PlatformFeeLedgerEntry::factory()->create(['reversed_entry_id' => $original->id]);
})->throws(QueryException::class);

it('enforces idempotency-key uniqueness across ledger entries', function (): void {
    PlatformFeeLedgerEntry::factory()->create(['idempotency_key' => 'earned:dup']);
    PlatformFeeLedgerEntry::factory()->create(['idempotency_key' => 'earned:dup']);
})->throws(QueryException::class);

// ---- append-only immutability (raw SQL) ----------------------------------------

it('blocks a raw UPDATE of a ledger monetary field', function (): void {
    $entry = PlatformFeeLedgerEntry::factory()->create();

    DB::table('platform_fee_ledger_entries')->where('id', $entry->id)
        ->update(['gross_platform_fee_minor' => 999999]);
})->throws(QueryException::class);

it('blocks a raw DELETE of a ledger entry', function (): void {
    $entry = PlatformFeeLedgerEntry::factory()->create();

    DB::table('platform_fee_ledger_entries')->where('id', $entry->id)->delete();
})->throws(QueryException::class);

it('carries a validation-source FK to payment_validation_events', function (): void {
    expect(Schema::hasColumn('platform_fee_ledger_entries', 'source_validation_event_id'))->toBeTrue();

    $fks = collect(DB::select(
        "select confrelid::regclass::text as referenced
         from pg_constraint
         where conrelid = 'platform_fee_ledger_entries'::regclass and contype = 'f'"
    ))->pluck('referenced')->all();

    expect($fks)->toContain('payment_validation_events');
});

it('enforces a structural unique invariant on the earned validation source', function (): void {
    $indexes = collect(DB::select(
        "select indexdef from pg_indexes where tablename = 'platform_fee_ledger_entries'"
    ))->pluck('indexdef')->implode("\n");

    // One earned entry per (validation event, source invoice item); NULLS NOT DISTINCT so invoice-level
    // (null item) rows also collide on replay of the same validation event.
    expect($indexes)->toContain('platform_fee_ledger_entries_validation_source_unique')
        ->and($indexes)->toContain('NULLS NOT DISTINCT');
});

it('permits a status + aggregation-link transition on a ledger entry', function (): void {
    $entry = PlatformFeeLedgerEntry::factory()->create();

    DB::table('platform_fee_ledger_entries')->where('id', $entry->id)
        ->update(['status' => 'reversed']);

    expect($entry->fresh()->status->value)->toBe('reversed');
});

it('blocks a raw UPDATE and DELETE of an adjustment', function (): void {
    $adjustment = PlatformFeeAdjustment::factory()->create();

    expect(fn () => DB::table('platform_fee_adjustments')->where('id', $adjustment->id)->update(['amount_minor' => -1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('platform_fee_adjustments')->where('id', $adjustment->id)->delete())
        ->toThrow(QueryException::class);
});

it('rejects a positive reversal adjustment (sign coherence)', function (): void {
    $entry = PlatformFeeLedgerEntry::factory()->create();

    PlatformFeeAdjustment::factory()->forEntry($entry)->create([
        'adjustment_type' => 'reversal',
        'amount_minor' => 250, // must be negative
    ]);
})->throws(QueryException::class);

// ---- disputes ------------------------------------------------------------------

it('rejects an escalated dispute status at the database CHECK', function (): void {
    // Raw UPDATE bypasses the PHP enum cast so the PostgreSQL CHECK is the guard under test
    // (the enum itself also rejects `escalated` — proven in Phase20EEnumParityTest).
    $dispute = PlatformFeeDispute::factory()->create();

    DB::table('platform_fee_disputes')->where('id', $dispute->id)->update(['status' => 'escalated']);
})->throws(QueryException::class);

it('rejects a dispute with no target', function (): void {
    PlatformFeeDispute::factory()->create([
        'platform_fee_ledger_entry_id' => null,
        'subscription_invoice_id' => null,
    ]);
})->throws(QueryException::class);

it('blocks a raw DELETE of a dispute', function (): void {
    $dispute = PlatformFeeDispute::factory()->create();

    DB::table('platform_fee_disputes')->where('id', $dispute->id)->delete();
})->throws(QueryException::class);

// ---- invoice expand coherence --------------------------------------------------

it('has the additive percentage-fee snapshot columns on invoices and invoice_items', function (): void {
    expect(Schema::hasColumn('invoices', 'platform_fee_configuration_id'))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'platform_fee_gross_minor'))->toBeTrue()
        ->and(Schema::hasColumn('invoice_items', 'platform_fee_item_gross_minor'))->toBeTrue()
        ->and(Schema::hasColumn('invoice_items', 'platform_fee_item_absorbed_minor'))->toBeTrue();
});

it('creates no historical platform-fee liabilities on a fresh migration', function (): void {
    expect(PlatformFeeLedgerEntry::query()->count())->toBe(0)
        ->and(PlatformFeeConfiguration::query()->count())->toBe(0)
        ->and(PlatformFeeAdjustment::query()->count())->toBe(0);
});
