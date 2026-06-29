<?php

declare(strict_types=1);

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('scheduling', 'appointments', 'appointments-schema');

it('creates the appointments table with the canonical columns on PostgreSQL', function (): void {
    expect(Schema::hasTable('appointments'))->toBeTrue();

    foreach ([
        'id', 'ulid', 'merchant_id', 'branch_id', 'client_id', 'service_id',
        'preferred_personnel_staff_profile_id', 'assigned_personnel_staff_profile_id',
        'starts_at', 'ends_at', 'status', 'cancellation_reason', 'transfer_reason',
        'checked_in_at', 'cancelled_at', 'no_show_at', 'created_by', 'created_at', 'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('appointments', $column))->toBeTrue("missing column {$column}");
    }
});

it('registers the table + model in TenantOwnership as branch-owned', function (): void {
    expect(TenantOwnership::BRANCH_OWNED)->toContain('appointments')
        ->and(TenantOwnership::COMPOSITE_CONSISTENCY)->toHaveKey('appointments')
        ->and(TenantOwnership::MODELS[Appointment::class])->toBe('branch');
});

it('uses BelongsToMerchant + BelongsToBranch and binds by ULID', function (): void {
    $traits = class_uses(Appointment::class);

    expect($traits)->toContain(BelongsToMerchant::class)
        ->and($traits)->toContain(BelongsToBranch::class)
        ->and((new Appointment)->getRouteKeyName())->toBe('ulid');
});

it('has the merchant-first, branch/date, client/date and personnel/date indexes', function (): void {
    $indexes = collect(DB::select('select indexdef from pg_indexes where tablename = ?', ['appointments']))
        ->map(fn ($r): string => (string) $r->indexdef)->implode("\n");

    expect($indexes)->toContain('merchant_id, branch_id')
        ->and($indexes)->toContain('branch_id, starts_at, status')
        ->and($indexes)->toContain('client_id, starts_at')
        ->and($indexes)->toContain('assigned_personnel_staff_profile_id, starts_at')
        ->and($indexes)->toContain('preferred_personnel_staff_profile_id, starts_at');
});

it('enforces a unique ULID', function (): void {
    $a = Appointment::factory()->create();

    expect(fn () => Appointment::factory()->create(['ulid' => $a->ulid]))
        ->toThrow(QueryException::class);
});

it('rejects an unsupported status value via the CHECK constraint', function (): void {
    $a = Appointment::factory()->create();

    // queued/in_service belong to 16B/16C — not in the 16A CHECK set.
    expect(fn () => DB::table('appointments')->where('id', $a->id)->update(['status' => 'queued']))
        ->toThrow(QueryException::class);
});

it('enforces starts_at < ends_at', function (): void {
    $a = Appointment::factory()->create();

    expect(fn () => DB::table('appointments')->where('id', $a->id)->update([
        'ends_at' => $a->starts_at->copy()->subMinute(),
    ]))->toThrow(QueryException::class);
});

it('rejects a cross-tenant client reference (composite FK)', function (): void {
    $a = Appointment::factory()->create();
    $foreign = Client::factory()->create(); // different merchant/branch

    expect(fn () => DB::table('appointments')->where('id', $a->id)->update(['client_id' => $foreign->id]))
        ->toThrow(QueryException::class);
});

it('rejects a cross-tenant service reference (composite FK)', function (): void {
    $a = Appointment::factory()->create();
    $foreign = Service::factory()->create();

    expect(fn () => DB::table('appointments')->where('id', $a->id)->update(['service_id' => $foreign->id]))
        ->toThrow(QueryException::class);
});

it('rejects a cross-tenant assigned-personnel reference (composite FK)', function (): void {
    $a = Appointment::factory()->create();
    $foreignStaff = StaffProfile::factory()->create();

    expect(fn () => DB::table('appointments')->where('id', $a->id)->update([
        'assigned_personnel_staff_profile_id' => $foreignStaff->id,
    ]))->toThrow(QueryException::class);
});

it('cannot bypass composite consistency with a raw cross-tenant insert', function (): void {
    $a = Appointment::factory()->create();
    $otherClient = Client::factory()->create();

    expect(fn () => DB::table('appointments')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $a->merchant_id,
        'branch_id' => $a->branch_id,
        'client_id' => $otherClient->id, // belongs to a different merchant
        'service_id' => $a->service_id,
        'starts_at' => now(),
        'ends_at' => now()->addMinutes(30),
        'status' => 'scheduled',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
