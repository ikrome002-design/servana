<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('audit', 'security');

/*
 | R2 re-asserts the Phase 8 guarantee (Plan §70, guardrail §6.5): audit_logs is
 | append-only — a PostgreSQL trigger blocks every UPDATE and DELETE.
 */

beforeEach(function (): void {
    $merchant = Merchant::factory()->active()->create();
    $this->log = app(AuditRecorder::class)->record(AuditEvent::LoginSuccess, null, $merchant->id);
});

// Each mutation runs in its own savepoint (nested DB::transaction). The trigger
// aborts that sub-transaction, so the abort is isolated from the surrounding
// RefreshDatabase transaction and later assertions can still query.

it('blocks an UPDATE at the database', function (): void {
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $this->log->id)->update(['action' => 'x'])))
        ->toThrow(QueryException::class);
});

it('blocks a DELETE at the database', function (): void {
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $this->log->id)->delete()))
        ->toThrow(QueryException::class);
});

it('leaves the row intact after blocked mutations', function (): void {
    try {
        DB::transaction(fn () => DB::table('audit_logs')->where('id', $this->log->id)->delete());
    } catch (QueryException) {
        // expected — the savepoint rolled back, the outer transaction survives
    }

    expect(AuditLog::query()->whereKey($this->log->id)->exists())->toBeTrue();
});
