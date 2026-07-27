<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Scheduling\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('search', 'phase22', 'security', 'tenancy');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | Cross-tenant and cross-branch isolation (Plan §68, §8.2, §73).
 |
 | Every scenario places a MATCHING row on the other side of the boundary, so a
 | pass means the boundary held while the row was genuinely findable — not that
 | the query simply found nothing.
 |==============================================================================
 */

it('never returns another merchant client, even with an identical name', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();
    $ulids = searchResultUlids($response, 'client');

    expect($ulids)->toContain($scn['clientA']->ulid)
        ->and($ulids)->not->toContain($foreign['client']->ulid);
});

it('never returns another merchant appointment', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    $mine = Appointment::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'client_id' => $scn['clientA']->id,
        'service_id' => $scn['serviceA']->id,
    ]);
    $theirs = Appointment::factory()->create([
        'merchant_id' => $foreign['merchant']->id,
        'branch_id' => $foreign['branch']->id,
        'client_id' => $foreign['client']->id,
    ]);

    $response = search($scn['frontOffice'], ['q' => 'Amina', 'types' => ['appointment']])->assertOk();
    $ulids = searchResultUlids($response, 'appointment');

    expect($ulids)->toContain($mine->ulid)
        ->and($ulids)->not->toContain($theirs->ulid);
});

it('never returns a row from a branch the caller is not assigned to', function (): void {
    $scn = searchScenario();

    // Both clients carry the same name; only branch A is in the actor's scope.
    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();
    $ulids = searchResultUlids($response, 'client');

    expect($ulids)->toContain($scn['clientA']->ulid)
        ->and($ulids)->not->toContain($scn['clientB']->ulid);
});

/**
 * A receipt in the given merchant+branch whose parent invoice number carries $token — the
 * `receipt` search matches an invoice number by ILIKE, so one token can place a MATCHING row on
 * both sides of a boundary without colliding with the unique receipt-number invariant.
 */
function receiptProbe(int $merchantId, int $branchId, int $clientId, string $token): Receipt
{
    // `issued()`, not the draft default: the `invoices_draft_unnumbered_check` DB invariant
    // forbids a numbered draft, and only a finalized invoice can carry a receipt anyway.
    $invoice = Invoice::factory()->issued()->create([
        'merchant_id' => $merchantId,
        'branch_id' => $branchId,
        'client_id' => $clientId,
        'invoice_number' => $token,
    ]);

    $group = PaymentRecordingGroup::factory()->create([
        'merchant_id' => $merchantId,
        'branch_id' => $branchId,
        'invoice_id' => $invoice->id,
        'status' => PaymentRecordingGroupStatus::Validated,
        'submitted_for_validation_at' => CarbonImmutable::now(),
        'validated_at' => CarbonImmutable::now(),
    ]);

    $event = PaymentValidationEvent::factory()->create([
        'payment_recording_group_id' => $group->id,
        'merchant_id' => $merchantId,
        'branch_id' => $branchId,
        'invoice_id' => $invoice->id,
    ]);

    return Receipt::factory()->create([
        'payment_validation_event_id' => $event->id,
        'merchant_id' => $merchantId,
        'branch_id' => $branchId,
        'invoice_id' => $invoice->id,
    ]);
}

it('lets a merchant-wide role reach every branch of its own merchant but no other merchant', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    // Merchant Admin is the one merchant-WIDE membership (TenantContext::isBranchScoped() is false
    // and branchIds() is empty, meaning "all own branches").
    //
    // The probe type is `receipt`, reached through the Merchant Admin's DEFAULT `receipt.view`.
    // It was `staff` until Phase 23 §14.1: `staff.view` is now active and HR-only, so a Merchant
    // Admin can no longer open `hr.staff-profile` and correctly no longer receives staff results
    // (see the case below). The probe changed; the property under test — branch EXPANSION for a
    // merchant-wide membership, with a MATCHING row on the far side of the tenant boundary — is
    // unchanged and still proven end to end.
    $admin = User::factory()->create();
    MerchantUser::factory()->create([
        'user_id' => $admin->id,
        'merchant_id' => $scn['merchant']->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    $inA = receiptProbe($scn['merchant']->id, $scn['branchA']->id, $scn['clientA']->id, 'INV-BRANCHPROBE-A');
    $inB = receiptProbe($scn['merchant']->id, $scn['branchB']->id, $scn['clientB']->id, 'INV-BRANCHPROBE-B');
    $foreignReceipt = receiptProbe($foreign['merchant']->id, $foreign['branch']->id, $foreign['client']->id, 'INV-BRANCHPROBE-X');

    $response = search($admin, ['q' => 'BRANCHPROBE', 'types' => ['receipt']])->assertOk();
    $ulids = searchResultUlids($response, 'receipt');

    expect($ulids)->toContain($inA->ulid)
        ->and($ulids)->toContain($inB->ulid)
        ->and($ulids)->not->toContain($foreignReceipt->ulid);
});

it('withholds staff results from a Merchant Admin, which cannot open a staff profile', function (): void {
    $scn = searchScenario();

    // Phase 23 §14.1 — search must never be a wider surface than the page its results link to.
    // The Merchant Admin holds the legacy `branches.manage_users_lifecycle` (staff MUTATION) but
    // NOT the HR-only `staff.view`, so `GET /api/v1/staff/{staff}` (the result's `hr.staff-profile`
    // target) is denied — and the staff search type must be withheld for the same reason.
    $admin = User::factory()->create();
    MerchantUser::factory()->create([
        'user_id' => $admin->id,
        'merchant_id' => $scn['merchant']->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    [, , $staffInA] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Personnel);
    $staffInA->update(['display_name' => 'Njeri Kamau']);

    // A caller with no authority over a type gets an empty collection, never a 403 (D-22-01).
    $response = search($admin, ['q' => 'Njeri', 'types' => ['staff']])->assertOk();
    expect(searchResultUlids($response, 'staff'))->toBe([]);

    // The linked detail page is denied too — the two are consistent.
    test()->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/staff/'.$staffInA->ulid)
        ->assertForbidden();

    // HR, which holds staff.view, still finds the same row — the boundary held while it was findable.
    [$hr] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Hr);
    $hrResponse = search($hr, ['q' => 'Njeri', 'types' => ['staff']])->assertOk();
    expect(searchResultUlids($hrResponse, 'staff'))->toContain($staffInA->ulid);
});

it('confines a branch-scoped HR member to its own branch for the same staff search', function (): void {
    $scn = searchScenario();

    [$hr] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Hr);

    [, , $staffInA] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Personnel);
    [, , $staffInB] = branchStaff($scn['merchant'], $scn['branchB'], MerchantUserRole::Personnel);

    $staffInA->update(['display_name' => 'Njeri Kamau']);
    $staffInB->update(['display_name' => 'Njeri Kamau']);

    $response = search($hr, ['q' => 'Njeri', 'types' => ['staff']])->assertOk();
    $ulids = searchResultUlids($response, 'staff');

    expect($ulids)->toContain($staffInA->ulid)
        ->and($ulids)->not->toContain($staffInB->ulid);
});

it('treats a requested branch outside the caller scope as a narrowing filter, not a widening one', function (): void {
    $scn = searchScenario();

    // Asking for branch B (which the actor cannot reach) intersects to an EMPTY branch set, so the
    // result is empty — never branch B's row, and never an error that would confirm B exists.
    $response = search($scn['frontOffice'], [
        'q' => 'Amina',
        'branch_ulids' => [$scn['branchB']->ulid],
    ])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('treats a foreign-merchant branch ulid as an unknown filter without leaking its existence', function (): void {
    $scn = searchScenario();
    $foreign = foreignSearchScenario();

    $response = search($scn['frontOffice'], [
        'q' => 'Amina',
        'branch_ulids' => [$foreign['branch']->ulid],
    ])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('honours a legitimate own-branch narrowing filter', function (): void {
    $scn = searchScenario();

    $response = search($scn['frontOffice'], [
        'q' => 'Amina',
        'branch_ulids' => [$scn['branchA']->ulid],
    ])->assertOk();

    expect(searchResultUlids($response, 'client'))->toContain($scn['clientA']->ulid);
});

it('stops returning a branch row the moment the branch assignment is removed', function (): void {
    $scn = searchScenario();

    search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();

    BranchUserAssignment::query()
        ->where('merchant_user_id', $scn['foMembership']->id)
        ->delete();

    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();

    // No reachable branch means "match nothing", never "match everything".
    expect($response->json('data'))->toBe([]);
});
