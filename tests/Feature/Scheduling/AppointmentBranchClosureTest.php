<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Branches\Services\BranchClosureGuard;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'appointments', 'appointments-closure', 'branches');

function branchAppointment(Merchant $m, MerchantBranch $b, AppointmentStatus $status, ?CarbonImmutable $start = null): Appointment
{
    $client = Client::factory()->create(['merchant_id' => $m->id, 'branch_id' => $b->id]);
    $service = Service::factory()->create(['merchant_id' => $m->id, 'branch_id' => $b->id]);
    $start ??= CarbonImmutable::now('Africa/Nairobi')->addDay()->setTime(10, 0);

    $attrs = [
        'merchant_id' => $m->id,
        'branch_id' => $b->id,
        'client_id' => $client->id,
        'service_id' => $service->id,
        'starts_at' => $start,
        'ends_at' => $start->addMinutes(30),
        'status' => $status,
    ];
    $attrs += match ($status) {
        AppointmentStatus::CheckedIn => ['checked_in_at' => CarbonImmutable::now()],
        AppointmentStatus::Cancelled => ['cancelled_at' => CarbonImmutable::now()],
        AppointmentStatus::NoShow => ['no_show_at' => CarbonImmutable::now()],
        default => [],
    };

    return Appointment::factory()->create($attrs);
}

it('blocks branch archival while an active appointment exists', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    branchAppointment($merchant, $branch, AppointmentStatus::Confirmed);

    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/branches/{$branch->ulid}/archive")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'branch_closure_blocked')
        ->assertJsonPath('error.meta.blockers', ['active_appointments']);
});

it('blocks archival for a future checked-in appointment (never silently stranded)', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    branchAppointment($merchant, $branch, AppointmentStatus::CheckedIn);

    expect(app(BranchClosureGuard::class)->blockers($branch))->toContain('active_appointments');
});

it('does not block archival for terminal cancelled or no-show appointments', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    branchAppointment($merchant, $branch, AppointmentStatus::Cancelled);
    branchAppointment($merchant, $branch, AppointmentStatus::NoShow);

    expect(app(BranchClosureGuard::class)->blockers($branch))->not->toContain('active_appointments');
});

it('does not leak another branch or another tenant appointment into the guard', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $otherMerchant = Merchant::factory()->active()->create();
    $otherTenantBranch = MerchantBranch::factory()->create(['merchant_id' => $otherMerchant->id]);

    branchAppointment($merchant, $otherBranch, AppointmentStatus::Confirmed);
    branchAppointment($otherMerchant, $otherTenantBranch, AppointmentStatus::Confirmed);

    // The clean branch is unaffected by appointments in another branch / tenant.
    expect(app(BranchClosureGuard::class)->blockers($branch))->not->toContain('active_appointments');
});

it('blocks a branch day close while a same-day active appointment exists', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$bm] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    // A same-day (today) active appointment.
    $today = CarbonImmutable::now('Africa/Nairobi')->setTime(10, 0);
    branchAppointment($merchant, $branch, AppointmentStatus::Confirmed, $today);

    // Phase 18B: satisfy the financial day-close gate (approved cash-up) so the
    // ONLY remaining blocker is the operational same-day appointment under test.
    BranchCashUp::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'business_date' => CarbonImmutable::now('Africa/Nairobi')->toDateString(),
        'status' => CashUpStatus::Locked,
    ]);

    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/branches/{$branch->ulid}/day/close")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'branch_closure_blocked')
        ->assertJsonPath('error.meta.blockers', ['active_appointments']);
});

it('allows a branch day close when the only active appointment is on a different day', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    // A FUTURE active appointment does not block TODAY's close.
    $future = CarbonImmutable::now('Africa/Nairobi')->addDays(3)->setTime(10, 0);
    branchAppointment($merchant, $branch, AppointmentStatus::Confirmed, $future);

    $today = CarbonImmutable::now('Africa/Nairobi')->toDateString();
    expect(app(BranchClosureGuard::class)->dayCloseBlockers($branch, $today))->toBe([]);
});
