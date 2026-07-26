<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Search\Definitions\ClientSearchDefinition;
use App\Domain\Search\DTO\SearchContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('search', 'phase22', 'security', 'permissions');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | Search is an AGGREGATOR, not an authority (decision D-22-01).
 |
 | Every type is admitted only after the caller's EXISTING per-type authority
 | passes, so search can never widen anyone's reach. These tests build one
 | matching row of every type in the actor's own branch, then assert that each
 | role sees exactly the types its own list/detail routes already allow.
 |==============================================================================
 */

/**
 * One matching row per indexed operational type, all in branch A, all discoverable by the term
 * "Amina" (client name) or "Signature" (service name).
 *
 * @param  array<string, mixed>  $scn
 * @return array<string, string> type => ulid
 */
function searchRowsOfEveryType(array $scn): array
{
    $appointment = Appointment::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'client_id' => $scn['clientA']->id,
        'service_id' => $scn['serviceA']->id,
    ]);

    $queueEntry = QueueEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'client_id' => $scn['clientA']->id,
        'service_id' => $scn['serviceA']->id,
    ]);

    $session = ServiceSession::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'client_id' => $scn['clientA']->id,
        'service_id' => $scn['serviceA']->id,
        'staff_profile_id' => $scn['foProfile']->id,
    ]);

    $invoice = Invoice::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branchA']->id,
        'client_id' => $scn['clientA']->id,
    ]);

    return [
        'client' => $scn['clientA']->ulid,
        'appointment' => $appointment->ulid,
        'queue_entry' => $queueEntry->ulid,
        'service_session' => $session->ulid,
        'invoice' => $invoice->ulid,
    ];
}

it('gives Front Office exactly the types its own routes already allow', function (): void {
    $scn = searchScenario();
    searchRowsOfEveryType($scn);

    $response = search($scn['frontOffice'], ['q' => 'Amina'])->assertOk();
    $types = searchResultTypes($response);

    // Front Office holds client.view + front_office.search, appointment.view, queue.view,
    // service_session.view, invoice.view and receipt.view.
    expect($types)->toContain('client')
        ->and($types)->toContain('appointment')
        ->and($types)->toContain('queue_entry')
        ->and($types)->toContain('service_session')
        ->and($types)->toContain('invoice')
        // Front Office is not a staff-lifecycle authority and holds no served-client key.
        ->and($types)->not->toContain('staff')
        ->and($types)->not->toContain('served_client');
});

it('gives a Branch Manager the dashboard-readable types and withholds clients and invoices', function (): void {
    $scn = searchScenario();
    searchRowsOfEveryType($scn);

    [$manager] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::BranchManager);

    $response = search($manager, ['q' => 'Amina'])->assertOk();
    $types = searchResultTypes($response);

    // `branch.dashboard.view` admits appointments and queue entries…
    expect($types)->toContain('appointment')
        ->and($types)->toContain('queue_entry')
        // …but Plan §10.2/§19.3 give a Branch Manager NO client key and NO invoice key, so search
        // must not become the back door to either.
        ->and($types)->not->toContain('client')
        ->and($types)->not->toContain('invoice')
        ->and($types)->not->toContain('service_session')
        ->and($types)->not->toContain('staff');
});

it('gives HR the staff type and withholds every operational client-facing type', function (): void {
    $scn = searchScenario();
    searchRowsOfEveryType($scn);

    [$hr] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Hr);
    [, , $subject] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Personnel);
    $subject->update(['display_name' => 'Amina Kiptoo']);

    $response = search($hr, ['q' => 'Amina'])->assertOk();
    $types = searchResultTypes($response);

    expect($types)->toContain('staff')
        ->and($types)->not->toContain('client')
        ->and($types)->not->toContain('appointment')
        ->and($types)->not->toContain('queue_entry')
        ->and($types)->not->toContain('invoice');
});

it('gives Finance the invoice type and withholds the client directory', function (): void {
    $scn = searchScenario();
    searchRowsOfEveryType($scn);

    [$finance] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Finance);

    $response = search($finance, ['q' => 'Amina'])->assertOk();
    $types = searchResultTypes($response);

    expect($types)->toContain('invoice')
        // Finance holds no `client.view`/`front_office.search` pair.
        ->and($types)->not->toContain('client')
        ->and($types)->not->toContain('staff');
});

it('withholds every type from an Audit member beyond its own read surface', function (): void {
    $scn = searchScenario();
    searchRowsOfEveryType($scn);

    [$audit] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::Audit);

    $response = search($audit, ['q' => 'Amina'])->assertOk();
    $types = searchResultTypes($response);

    // Audit is read-only and holds `receipt.view` only among the catalogue authorities.
    expect($types)->not->toContain('client')
        ->and($types)->not->toContain('appointment')
        ->and($types)->not->toContain('queue_entry')
        ->and($types)->not->toContain('service_session')
        ->and($types)->not->toContain('invoice')
        ->and($types)->not->toContain('staff');
});

it('restricts the requested type set to what the caller may actually search', function (): void {
    $scn = searchScenario();
    searchRowsOfEveryType($scn);

    [$manager] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::BranchManager);

    // Explicitly asking for a type the caller cannot search is silently excluded — not a 403, which
    // would confirm the type exists and that this role is the wrong one for it.
    $response = search($manager, ['q' => 'Amina', 'types' => ['client', 'invoice']])->assertOk();

    expect($response->json('data'))->toBe([])
        ->and(searchResultTypes($response))->toBe([]);
});

it('requires BOTH client.view and front_office.search before a client is searchable', function (): void {
    $scn = searchScenario();

    $definition = app(ClientSearchDefinition::class);
    $user = User::factory()->create();

    $withBoth = new SearchContext(
        user: $user,
        merchantId: $scn['merchant']->id,
        branchIds: [$scn['branchA']->id],
        isBranchScoped: true,
        staffProfileId: null,
        permissions: ['client.view', 'front_office.search'],
    );
    $listOnly = new SearchContext(
        user: $user,
        merchantId: $scn['merchant']->id,
        branchIds: [$scn['branchA']->id],
        isBranchScoped: true,
        staffProfileId: null,
        permissions: ['client.view'],
    );
    $searchOnly = new SearchContext(
        user: $user,
        merchantId: $scn['merchant']->id,
        branchIds: [$scn['branchA']->id],
        isBranchScoped: true,
        staffProfileId: null,
        permissions: ['front_office.search'],
    );

    // The live client list treats SEARCHING as a capability distinct from LISTING
    // (ClientController::index aborts 403 on `q` without `front_office.search`), and search honours
    // that split exactly rather than collapsing it.
    expect($definition->canSearch($withBoth))->toBeTrue()
        ->and($definition->canSearch($listOnly))->toBeFalse()
        ->and($definition->canSearch($searchOnly))->toBeFalse();
});
