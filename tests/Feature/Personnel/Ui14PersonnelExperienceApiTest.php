<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Models\QueueEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('ui14', 'personnel', 'own-scope');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('builds the private workspace only from the authenticated staff profile', function (): void {
    $scenario = smsScenario();
    [, , $otherStaff] = branchStaff($scenario['merchant'], $scenario['branch'], MerchantUserRole::Personnel);
    smsServedClient($scenario['merchant'], $scenario['branch'], $otherStaff, $scenario['service'], phone: '+254799991234');

    $response = $this->actingAs($scenario['user'], 'sanctum')
        ->getJson('/api/v1/personnel/me/workspace?staff_profile_ulid='.$otherStaff->ulid)
        ->assertOk()
        ->assertJsonPath('data.staff.id', $scenario['staff']->ulid)
        ->assertJsonPath('data.clients.served', 1)
        ->assertJsonPath('data.clients.can_compose_sms', true)
        ->assertJsonPath('data.availability.can.update', false);

    expect($response->getContent())
        ->not->toContain($otherStaff->ulid)
        ->not->toContain('+254799991234');
});

it('returns own service history with masked client context and the validated-payment commission rule', function (): void {
    $scenario = smsScenario();
    [, , $otherStaff] = branchStaff($scenario['merchant'], $scenario['branch'], MerchantUserRole::Personnel);
    $otherClient = smsServedClient($scenario['merchant'], $scenario['branch'], $otherStaff, $scenario['service'], phone: '+254700009999');

    $response = $this->actingAs($scenario['user'], 'sanctum')
        ->getJson('/api/v1/personnel/me/service-history?sort=-completed_at')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.client.id', $scenario['client']->ulid)
        ->assertJsonPath('data.0.client.phone_masked', '••• ••• 5678')
        ->assertJsonPath(
            'data.0.commission.explanation',
            'Completion alone does not earn commission. Commission becomes earned only after Finance validates payment.',
        );

    expect($response->getContent())
        ->not->toContain('+254712345678')
        ->not->toContain($otherClient->ulid)
        ->not->toContain('+254700009999');
});

it('shows preferred requests without leaking another assignee identity', function (): void {
    $scenario = smsScenario();
    QueueEntry::factory()->create([
        'merchant_id' => $scenario['merchant']->id,
        'branch_id' => $scenario['branch']->id,
        'client_id' => $scenario['client']->id,
        'service_id' => $scenario['service']->id,
        'preferred_personnel_staff_profile_id' => $scenario['staff']->id,
        'staff_profile_id' => null,
    ]);

    $response = $this->actingAs($scenario['user'], 'sanctum')
        ->getJson('/api/v1/personnel/me/preferred-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.assigned_to_you', false)
        ->assertJsonPath('data.0.assignment_label', 'Request only or reassigned');

    expect($response->getContent())
        ->not->toContain('staff_profile_id')
        ->not->toContain('assigned_personnel_staff_profile_id');
});

it('audits the served-client relationship view and never returns a contact export shape', function (): void {
    $scenario = smsScenario();

    $response = $this->actingAs($scenario['user'], 'sanctum')
        ->getJson('/api/v1/personnel/me/served-clients')
        ->assertOk()
        ->assertJsonPath('data.0.id', $scenario['client']->ulid)
        ->assertJsonPath('data.0.phone_masked', '••• ••• 5678')
        ->assertJsonPath('data.0.visit_count', 1)
        ->assertJsonPath('data.0.sms_consent', 'opted_in');

    expect($response->getContent())
        ->not->toContain('+254712345678')
        ->not->toContain('phone_encrypted')
        ->not->toContain('phone_index')
        ->not->toContain('email');

    expect(AuditLog::query()->where('action', 'personnel.served_clients.viewed')->count())->toBe(1);
    $context = AuditLog::query()->where('action', 'personnel.served_clients.viewed')->firstOrFail()->context;
    expect($context)->toMatchArray(['scope' => 'own', 'contact_shape' => 'masked', 'export' => false]);
});

it('returns only own authoritative commission and salary ledger facts', function (): void {
    $scenario = smsScenario();
    [, , $otherStaff] = branchStaff($scenario['merchant'], $scenario['branch'], MerchantUserRole::Personnel);
    $ownCommission = earnedCommission($scenario['branch'], $scenario['staff'], 12345);
    earnedCommission($scenario['branch'], $otherStaff, 99999);
    $ownSalary = pendingSalary($scenario['branch'], $scenario['staff'], 7654321);
    pendingSalary($scenario['branch'], $otherStaff, 8888888);

    $commissions = $this->actingAs($scenario['user'], 'sanctum')
        ->getJson('/api/v1/personnel/me/commissions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownCommission->ulid)
        ->assertJsonPath('data.0.amount_minor', 12345);

    $salary = $this->actingAs($scenario['user'], 'sanctum')
        ->getJson('/api/v1/personnel/me/salary')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownSalary->ulid)
        ->assertJsonPath('data.0.amount_minor', 7654321);

    expect($commissions->getContent())->not->toContain('99999')
        ->and($salary->getContent())->not->toContain('8888888')
        ->and(CommissionLedgerEntry::query()->count())->toBe(2)
        ->and(SalaryLedgerEntry::query()->count())->toBe(2);
});

it('keeps Personnel availability strictly read-only and derives the subject from membership', function (): void {
    $scenario = smsScenario();

    $this->actingAs($scenario['user'], 'sanctum')
        ->getJson('/api/v1/personnel/me/availability')
        ->assertOk()
        ->assertJsonPath('data.staff.id', $scenario['staff']->ulid)
        ->assertJsonPath('data.can.update', false);

    $this->actingAs($scenario['user'], 'sanctum')
        ->putJson('/api/v1/personnel/me/availability', ['recurring' => []])
        ->assertMethodNotAllowed();
});

it('denies all UI-14 own-experience reads to non-Personnel roles', function (string $path): void {
    $scenario = smsScenario();
    [$frontOffice] = branchStaff($scenario['merchant'], $scenario['branch'], MerchantUserRole::FrontOffice);

    $this->actingAs($frontOffice, 'sanctum')->getJson($path)->assertForbidden();
})->with([
    '/api/v1/personnel/me/workspace',
    '/api/v1/personnel/me/service-history',
    '/api/v1/personnel/me/preferred-requests',
    '/api/v1/personnel/me/served-clients',
    '/api/v1/personnel/me/commissions',
    '/api/v1/personnel/me/salary',
    '/api/v1/personnel/me/availability',
]);

it('rejects unbounded and contact-shaped filters', function (): void {
    $scenario = smsScenario();

    $this->actingAs($scenario['user'], 'sanctum')->getJson('/api/v1/personnel/me/service-history?per_page=1000')->assertUnprocessable();
    $this->actingAs($scenario['user'], 'sanctum')->getJson('/api/v1/personnel/me/served-clients?sort=phone_last_four')->assertUnprocessable();
    $this->actingAs($scenario['user'], 'sanctum')->getJson('/api/v1/personnel/me/commissions?sort=amount_minor')->assertUnprocessable();
});

it('never returns another tenant’s clients, ledgers or preferred requests in any own-experience read', function (): void {
    $scenario = smsScenario();
    $foreign = smsScenario();
    $foreignClient = smsServedClient($foreign['merchant'], $foreign['branch'], $foreign['staff'], $foreign['service'], phone: '+254733330000');
    earnedCommission($foreign['branch'], $foreign['staff'], 77777);
    pendingSalary($foreign['branch'], $foreign['staff'], 6666666);
    QueueEntry::factory()->create([
        'merchant_id' => $foreign['merchant']->id,
        'branch_id' => $foreign['branch']->id,
        'client_id' => $foreignClient->id,
        'service_id' => $foreign['service']->id,
        'preferred_personnel_staff_profile_id' => $foreign['staff']->id,
    ]);

    foreach (['workspace', 'service-history', 'preferred-requests', 'served-clients', 'commissions', 'salary', 'availability'] as $path) {
        $body = $this->actingAs($scenario['user'], 'sanctum')->getJson('/api/v1/personnel/me/'.$path)->assertOk()->getContent();

        expect($body)
            ->not->toContain($foreignClient->ulid)
            ->not->toContain($foreign['staff']->ulid)
            ->not->toContain('+254733330000')
            ->not->toContain('77777')
            ->not->toContain('6666666');
    }
});

it('excludes clients and preferred requests that belong only to another Personnel member in the same branch', function (): void {
    $scenario = smsScenario();
    [, , $otherStaff] = branchStaff($scenario['merchant'], $scenario['branch'], MerchantUserRole::Personnel);
    $otherClient = smsServedClient($scenario['merchant'], $scenario['branch'], $otherStaff, $scenario['service'], phone: '+254744440000');
    QueueEntry::factory()->create([
        'merchant_id' => $scenario['merchant']->id,
        'branch_id' => $scenario['branch']->id,
        'client_id' => $otherClient->id,
        'service_id' => $scenario['service']->id,
        'preferred_personnel_staff_profile_id' => $otherStaff->id,
    ]);

    $this->actingAs($scenario['user'], 'sanctum')
        ->getJson('/api/v1/personnel/me/served-clients')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['id' => $otherClient->ulid]);

    $this->actingAs($scenario['user'], 'sanctum')
        ->getJson('/api/v1/personnel/me/preferred-requests')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('requires authentication for every own-experience read', function (string $path): void {
    $this->getJson($path)->assertUnauthorized();
})->with([
    '/api/v1/personnel/me/workspace',
    '/api/v1/personnel/me/service-history',
    '/api/v1/personnel/me/preferred-requests',
    '/api/v1/personnel/me/served-clients',
    '/api/v1/personnel/me/commissions',
    '/api/v1/personnel/me/salary',
    '/api/v1/personnel/me/availability',
]);

it('denies own-experience reads once the Personnel membership is suspended', function (): void {
    $scenario = smsScenario();
    app(StaffLifecycleService::class)->suspend($scenario['membership']);

    $status = $this->actingAs($scenario['user']->fresh(), 'sanctum')
        ->getJson('/api/v1/personnel/me/served-clients')
        ->getStatusCode();

    expect($status)->toBeIn([401, 403]);
});
