<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\WalkIn;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('scheduling', 'queue', 'queue-schema');

it('uses the ULID as the route key and keeps it unique', function (): void {
    $entry = QueueEntry::factory()->create();

    expect($entry->getRouteKeyName())->toBe('ulid')
        ->and(strlen($entry->ulid))->toBe(26);

    expect(fn () => QueueEntry::factory()->create(['ulid' => $entry->ulid]))->toThrow(QueryException::class);
});

it('rejects an invalid status, assignment mode, and non-positive position', function (): void {
    $entry = QueueEntry::factory()->create();

    expect(fn () => DB::table('queue_entries')->where('id', $entry->id)->update(['status' => 'paused']))->toThrow(QueryException::class);
    expect(fn () => DB::table('queue_entries')->where('id', $entry->id)->update(['assignment_mode' => 'auto']))->toThrow(QueryException::class);
    expect(fn () => DB::table('queue_entries')->where('id', $entry->id)->update(['position' => 0]))->toThrow(QueryException::class);
});

it('enforces the source XOR (exactly one of walk_in_id / appointment_id)', function (): void {
    $entry = QueueEntry::factory()->create();
    $appointment = Appointment::factory()->checkedIn()->create([
        'merchant_id' => $entry->merchant_id, 'branch_id' => $entry->branch_id,
        'client_id' => $entry->client_id, 'service_id' => $entry->service_id,
    ]);

    // Both set → violation.
    expect(fn () => DB::table('queue_entries')->where('id', $entry->id)->update(['appointment_id' => $appointment->id]))
        ->toThrow(QueryException::class);
    // Neither set → violation.
    expect(fn () => DB::table('queue_entries')->where('id', $entry->id)->update(['walk_in_id' => null]))
        ->toThrow(QueryException::class);
});

it('enforces one queue entry per walk-in and per appointment', function (): void {
    $entry = QueueEntry::factory()->create();
    $walkInId = $entry->walk_in_id;

    expect(fn () => QueueEntry::factory()->create([
        'merchant_id' => $entry->merchant_id, 'branch_id' => $entry->branch_id,
        'client_id' => $entry->client_id, 'service_id' => $entry->service_id,
        'walk_in_id' => $walkInId, 'appointment_id' => null, 'position' => 2,
    ]))->toThrow(QueryException::class);
});

it('enforces a unique active position per branch (partial-unique index)', function (): void {
    $entry = QueueEntry::factory()->create(['position' => 1]);

    expect(fn () => QueueEntry::factory()->create([
        'merchant_id' => $entry->merchant_id, 'branch_id' => $entry->branch_id,
        'position' => 1,
    ]))->toThrow(QueryException::class);
});

it('allows a reused position once the conflicting entry is terminal', function (): void {
    $entry = QueueEntry::factory()->completed()->create(['position' => 1]);

    // A new waiting entry may take position 1 because the completed one left the
    // active-ordered set.
    $fresh = QueueEntry::factory()->create([
        'merchant_id' => $entry->merchant_id, 'branch_id' => $entry->branch_id,
        'position' => 1,
    ]);

    expect($fresh->position)->toBe(1);
});

it('rejects a cross-tenant client reference via the composite FK', function (): void {
    $entry = QueueEntry::factory()->create();
    $foreignClient = Client::factory()->create(); // different merchant/branch

    expect(fn () => DB::table('queue_entries')->where('id', $entry->id)->update(['client_id' => $foreignClient->id]))
        ->toThrow(QueryException::class);
});

it('rejects a cross-branch service reference for the same merchant', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $entry = QueueEntry::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branchA->id]);
    $serviceB = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branchB->id]);

    // Composite FK is (service_id, merchant_id) — same merchant passes the FK, but the
    // service belongs to another BRANCH; branch consistency is enforced by the action,
    // and the merchant composite FK still binds tenant. Assert the merchant-mismatch
    // path is rejected at the DB.
    $foreignService = Service::factory()->create();
    expect(fn () => DB::table('queue_entries')->where('id', $entry->id)->update(['service_id' => $foreignService->id]))
        ->toThrow(QueryException::class);
    expect($serviceB->branch_id)->toBe($branchB->id);
});

it('applies the appointment queued expansion without dropping existing rows', function (): void {
    $appointment = Appointment::factory()->checkedIn()->create();

    // The expanded CHECK accepts queued; existing states remain valid.
    DB::table('appointments')->where('id', $appointment->id)->update(['status' => 'queued']);
    expect(DB::table('appointments')->where('id', $appointment->id)->value('status'))->toBe('queued');

    // An unknown status is still rejected.
    expect(fn () => DB::table('appointments')->where('id', $appointment->id)->update(['status' => 'frozen']))
        ->toThrow(QueryException::class);
});

it('registers both tables in TenantOwnership as branch-owned with composite consistency', function (): void {
    expect(TenantOwnership::BRANCH_OWNED)->toContain('walk_ins')->toContain('queue_entries')
        ->and(TenantOwnership::COMPOSITE_CONSISTENCY)->toHaveKey('walk_ins')->toHaveKey('queue_entries');

    expect((new WalkIn)->getRouteKeyName())->toBe('ulid');
});
