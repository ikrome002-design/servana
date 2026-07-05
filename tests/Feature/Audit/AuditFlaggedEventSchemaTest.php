<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditFlaggedEventStatus;
use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('audit');

/*
 | Phase 19 — audit_flagged_events schema: branch-owned review record over ONE immutable
 | audit_logs row. Proves the columns, the lifecycle/resolution/assignment CHECKs, the
 | restrict-on-delete linkage to the append-only source, and the composite tenant FK.
 */

it('has the Plan §13.2 columns and no soft-delete column', function (): void {
    $cols = Schema::getColumnListing('audit_flagged_events');

    expect($cols)->toContain(
        'id', 'ulid', 'merchant_id', 'branch_id', 'audit_log_id', 'status',
        'review_notes', 'assigned_to', 'resolved_by', 'created_by', 'created_at', 'updated_at',
    )->and($cols)->not->toContain('deleted_at');
});

it('rejects a status outside the flagged-event lifecycle', function (): void {
    $flag = AuditFlaggedEvent::factory()->create();

    expect(fn () => DB::table('audit_flagged_events')->where('id', $flag->id)
        ->update(['status' => 'archived']))
        ->toThrow(QueryException::class);
});

it('rejects a terminal review outcome without a resolver + notes', function (): void {
    $flag = AuditFlaggedEvent::factory()->underReview()->create();

    // A single deliberately-failing write per test — Postgres aborts the whole
    // transaction on a CHECK violation, so the accept case lives in its own test.
    expect(fn () => DB::table('audit_flagged_events')->where('id', $flag->id)
        ->update(['status' => 'resolved']))
        ->toThrow(QueryException::class);
});

it('accepts a terminal review outcome with a resolver + notes', function (): void {
    $flag = AuditFlaggedEvent::factory()->underReview()->create();

    DB::table('audit_flagged_events')->where('id', $flag->id)->update([
        'status' => 'resolved',
        'resolved_by' => $flag->assigned_to,
        'review_notes' => 'Benign.',
    ]);

    expect(AuditFlaggedEvent::find($flag->id)->status)->toBe(AuditFlaggedEventStatus::Resolved);
});

it('requires an assignee while under review', function (): void {
    $flag = AuditFlaggedEvent::factory()->create(); // open, no assignee

    expect(fn () => DB::table('audit_flagged_events')->where('id', $flag->id)
        ->update(['status' => 'under_review']))
        ->toThrow(QueryException::class);
});

it('refuses to drop the audited source row it references (RESTRICT)', function (): void {
    $flag = AuditFlaggedEvent::factory()->create();

    expect(fn () => DB::table('audit_logs')->where('id', $flag->audit_log_id)->delete())
        ->toThrow(QueryException::class);
});

it('enforces same-merchant branch linkage via the composite FK', function (): void {
    $flag = AuditFlaggedEvent::factory()->create();
    $foreignBranch = MerchantBranch::factory()->create(); // different merchant

    expect(fn () => DB::table('audit_flagged_events')->where('id', $flag->id)
        ->update(['branch_id' => $foreignBranch->id]))
        ->toThrow(QueryException::class);
});

it('generates a unique ULID public identifier and uses it as the route key', function (): void {
    $flag = AuditFlaggedEvent::factory()->create();

    expect($flag->ulid)->toHaveLength(26)
        ->and($flag->getRouteKeyName())->toBe('ulid');

    $log = AuditLog::factory()->create();
    expect(fn () => AuditFlaggedEvent::factory()->create([
        'ulid' => $flag->ulid,
        'audit_log_id' => $log->id,
        'merchant_id' => $log->merchant_id,
        'branch_id' => $log->branch_id,
        'created_by' => User::factory(),
    ]))->toThrow(QueryException::class);

    unset($log);
    Str::createUlidsNormally();
});
