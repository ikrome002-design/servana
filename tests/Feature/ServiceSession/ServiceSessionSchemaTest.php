<?php

declare(strict_types=1);

use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('scheduling', 'service-session', 'service-session-schema');

it('creates the service_sessions table with the expected columns', function (): void {
    expect(Schema::hasTable('service_sessions'))->toBeTrue();

    foreach ([
        'id', 'ulid', 'merchant_id', 'branch_id', 'queue_entry_id', 'client_id',
        'service_id', 'staff_profile_id', 'status', 'started_at', 'completed_at',
        'cancelled_at', 'cancellation_reason', 'notes', 'preferred_personnel_honored',
        'created_by', 'created_at', 'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('service_sessions', $column))->toBeTrue();
    }

    // NO appointment_id (Gate A: provenance via queue_entries.appointment_id).
    expect(Schema::hasColumn('service_sessions', 'appointment_id'))->toBeFalse();
});

it('registers the table + model in TenantOwnership (branch-owned, composite consistency)', function (): void {
    expect(TenantOwnership::BRANCH_OWNED)->toContain('service_sessions')
        ->and(TenantOwnership::MODELS[ServiceSession::class])->toBe('branch')
        ->and(TenantOwnership::COMPOSITE_CONSISTENCY['service_sessions'])
        ->toBe(['parent' => 'merchant_branches', 'fk' => 'branch_id']);
});

it('is listed in the migration manifest', function (): void {
    $manifest = file_get_contents(base_path('docs/architecture/migrations/manifest.yaml'));
    expect($manifest)->toContain('2026_06_30_000001_create_service_sessions_table.php')
        ->and($manifest)->toContain('table: service_sessions');
});

it('resolves the ULID as the route key (never the bigint id)', function (): void {
    $session = ServiceSession::factory()->create();

    expect($session->getRouteKeyName())->toBe('ulid')
        ->and(strlen($session->ulid))->toBe(26);
});

it('rejects an unsupported status via the CHECK constraint', function (): void {
    $session = ServiceSession::factory()->create();

    expect(fn () => DB::table('service_sessions')->where('id', $session->id)->update(['status' => 'paused']))
        ->toThrow(QueryException::class);
});

it('enforces status/timestamp coherence (completed requires completed_at)', function (): void {
    $session = ServiceSession::factory()->inProgress()->create();

    expect(fn () => DB::table('service_sessions')->where('id', $session->id)
        ->update(['status' => 'completed', 'completed_at' => null]))
        ->toThrow(QueryException::class);
});

it('requires a cancellation reason for a cancelled session', function (): void {
    $session = ServiceSession::factory()->create();

    expect(fn () => DB::table('service_sessions')->where('id', $session->id)
        ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => null]))
        ->toThrow(QueryException::class);
});

it('database-prevents a second active session for the same personnel (duplicate-active)', function (): void {
    $active = ServiceSession::factory()->inProgress()->create();

    $dup = ServiceSession::factory()->make([
        'merchant_id' => $active->merchant_id,
        'branch_id' => $active->branch_id,
        'client_id' => $active->client_id,
        'service_id' => $active->service_id,
        'staff_profile_id' => $active->staff_profile_id,
        'queue_entry_id' => null, // isolate the active-staff partial-unique
        'status' => 'pending',
    ]);

    expect(fn () => $dup->save())->toThrow(QueryException::class);
});

it('allows different personnel to each hold an active session', function (): void {
    ServiceSession::factory()->inProgress()->create();
    $second = ServiceSession::factory()->inProgress()->create();

    expect(ServiceSession::query()->whereIn('status', ['pending', 'in_progress'])->count())->toBe(2)
        ->and($second->exists)->toBeTrue();
});

it('releases the active constraint once a session completes', function (): void {
    $first = ServiceSession::factory()->completed()->create();

    // Same personnel may now hold a new active session (the first is terminal).
    $second = ServiceSession::factory()->make([
        'merchant_id' => $first->merchant_id,
        'branch_id' => $first->branch_id,
        'client_id' => $first->client_id,
        'service_id' => $first->service_id,
        'staff_profile_id' => $first->staff_profile_id,
        'queue_entry_id' => null,
        'status' => 'in_progress',
        'started_at' => now(),
    ]);
    $second->save();

    expect($second->exists)->toBeTrue();
});

it('database-prevents two sessions for one queue entry (UNIQUE queue_entry_id)', function (): void {
    $first = ServiceSession::factory()->inProgress()->create();

    $dup = ServiceSession::factory()->make([
        'merchant_id' => $first->merchant_id,
        'branch_id' => $first->branch_id,
        'client_id' => $first->client_id,
        'service_id' => $first->service_id,
        'staff_profile_id' => $first->staff_profile_id,
        'queue_entry_id' => $first->queue_entry_id, // same source
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'cancellation_reason' => 'x',
    ]);

    expect(fn () => $dup->save())->toThrow(QueryException::class);
});

it('has the required indexes including the active partial-unique', function (): void {
    $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'service_sessions'"))
        ->pluck('indexname')->all();

    expect($indexes)->toContain('service_sessions_active_staff_unique')
        ->toContain('service_sessions_queue_entry_id_unique')
        ->toContain('service_sessions_id_merchant_id_unique')
        ->toContain('service_sessions_branch_id_status_index')
        ->toContain('service_sessions_staff_profile_id_status_index');
});
