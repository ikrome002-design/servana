<?php

declare(strict_types=1);

use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Models\ServiceSession;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('invoicing', 'invoice-api');

/** A completed, un-invoiced service session for the scenario client/branch. */
function apiSession(array $scn, ?bool $preferredHonored = null): ServiceSession
{
    return ServiceSession::factory()->completed()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'service_id' => $scn['service']->id,
        'staff_profile_id' => $scn['staff']->id,
        'queue_entry_id' => null,
        'preferred_personnel_honored' => $preferredHonored,
    ]);
}

/** A Finance member of the scenario merchant + branch (mandatory MFA auto-asserted). */
function apiFinance(array $scn)
{
    [$finance] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Finance);

    return $finance;
}

/** Draft an invoice through the API as Front Office; returns the invoice ULID. */
function draftViaApi(array $scn): string
{
    $session = apiSession($scn);

    return test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson('/api/v1/invoices', [
            'client_id' => $scn['client']->ulid,
            'service_session_ids' => [$session->ulid],
        ])->assertCreated()->json('data.id');
}

it('lets Front Office create a draft invoice with a masked client and no number', function (): void {
    $scn = queueScenario();
    $session = apiSession($scn);

    $response = test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson('/api/v1/invoices', [
            'client_id' => $scn['client']->ulid,
            'service_session_ids' => [$session->ulid],
        ])->assertCreated();

    expect($response->json('data.status'))->toBe('draft')
        ->and($response->json('data.invoice_number'))->toBeNull()
        ->and($response->json('data.is_draft'))->toBeTrue()
        ->and($response->json('data.total.amount'))->toBe((int) $scn['service']->price_minor)
        ->and($response->json('data.client.phone_masked'))->toContain('•')
        ->and($response->json('data.client'))->not->toHaveKey('phone')
        ->and($response->json('data.client'))->not->toHaveKey('phone_encrypted')
        ->and($response->json('data.items.0.id'))->toBeString();
});

it('finalizes a draft (financial_mutation) only with an Idempotency-Key', function (): void {
    $scn = queueScenario();
    $invoiceId = draftViaApi($scn);

    // Missing Idempotency-Key → 422 idempotency_key_required.
    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceId}/finalize")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');

    $response = test()->actingAs($scn['frontOffice'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/invoices/{$invoiceId}/finalize")
        ->assertOk();

    expect($response->json('data.status'))->toBe('issued')
        ->and($response->json('data.invoice_number'))->toBe($scn['branch']->code.'-INV-000001');
});

it('replays an identical finalize without allocating a second number, item, or audit', function (): void {
    $scn = queueScenario();
    $invoiceId = draftViaApi($scn);
    $key = (string) Str::uuid();

    $first = test()->actingAs($scn['frontOffice'], 'sanctum')
        ->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/invoices/{$invoiceId}/finalize")->assertOk();

    $replay = test()->actingAs($scn['frontOffice'], 'sanctum')
        ->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/invoices/{$invoiceId}/finalize")->assertOk();

    expect($replay->json('data.invoice_number'))->toBe($first->json('data.invoice_number'))
        ->and(Invoice::query()->count())->toBe(1)
        ->and(InvoiceItem::query()->where('invoice_id', Invoice::query()->value('id'))->count())->toBe(1);

    // The per-merchant sequence advanced exactly once (next_value = 2).
    expect(InvoiceNumberSequence::query()->value('next_value'))->toBe(2);
});

it('rejects an Idempotency-Key reused with a different request', function (): void {
    $scn = queueScenario();
    $a = draftViaApi($scn);
    $b = draftViaApi($scn);
    $key = (string) Str::uuid();

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/invoices/{$a}/finalize")->assertOk();

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/invoices/{$b}/finalize")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused_with_different_request');
});

it('denies invoice creation to Branch Manager, Merchant Admin, HR, Personnel, and Audit', function (MerchantUserRole $role): void {
    $scn = queueScenario();
    [$user] = branchStaff($scn['merchant'], $scn['branch'], $role);
    $session = apiSession($scn);

    test()->actingAs($user, 'sanctum')
        ->postJson('/api/v1/invoices', [
            'client_id' => $scn['client']->ulid,
            'service_session_ids' => [$session->ulid],
        ])->assertForbidden();
})->with([
    'branch manager' => [MerchantUserRole::BranchManager],
    'merchant admin' => [MerchantUserRole::MerchantAdmin],
    'hr' => [MerchantUserRole::Hr],
    'personnel' => [MerchantUserRole::Personnel],
    'audit' => [MerchantUserRole::Audit],
]);

it('denies Front Office the Finance void and adjust operations', function (): void {
    $scn = queueScenario();
    $invoiceId = draftViaApi($scn);
    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/invoices/{$invoiceId}/finalize")->assertOk();

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceId}/void", ['reason' => 'x'])->assertForbidden();
    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceId}/adjust", ['reason' => 'x'])->assertForbidden();
});

it('lets Finance run the additive void workflow (request → execute) non-destructively', function (): void {
    $scn = queueScenario();
    $finance = apiFinance($scn);
    $invoiceId = draftViaApi($scn);
    $finalized = test()->actingAs($scn['frontOffice'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/invoices/{$invoiceId}/finalize")->assertOk();

    $number = $finalized->json('data.invoice_number');
    $total = (int) $finalized->json('data.total.amount');

    test()->actingAs($finance, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceId}/void", ['reason' => 'Duplicate invoice.'])
        ->assertOk()->assertJsonPath('data.status', 'void_pending');

    test()->actingAs($finance, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceId}/void/execute")
        ->assertOk()->assertJsonPath('data.status', 'voided');

    // Number retained; items + total snapshot unchanged; nothing deleted.
    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->invoice_number)->toBe($number)
        ->and($invoice->total_minor)->toBe($total)
        ->and($invoice->items()->count())->toBe(1);
});

it('requires a reason for a Finance void request', function (): void {
    $scn = queueScenario();
    $finance = apiFinance($scn);
    $invoiceId = draftViaApi($scn);
    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/invoices/{$invoiceId}/finalize")->assertOk();

    test()->actingAs($finance, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceId}/void", [])
        ->assertStatus(422);
});

it('returns 423 financial_period_locked when the period is locked', function (): void {
    app()->bind(PeriodLockRepository::class, fn () => new class implements PeriodLockRepository
    {
        public function isLocked(int $merchantId, ?int $branchId, CarbonInterface $businessDate): bool
        {
            return true;
        }
    });

    $scn = queueScenario();
    $session = apiSession($scn);

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson('/api/v1/invoices', [
            'client_id' => $scn['client']->ulid,
            'service_session_ids' => [$session->ulid],
        ])
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'financial_period_locked');
});

it('returns 404 for a foreign-tenant invoice ULID (no existence leak)', function (): void {
    $scn = queueScenario();
    $invoiceId = draftViaApi($scn);

    $otherScn = queueScenario();
    test()->actingAs($otherScn['frontOffice'], 'sanctum')
        ->getJson("/api/v1/invoices/{$invoiceId}")
        ->assertNotFound();
});

it('exposes no destructive or payment routes for an invoice', function (): void {
    $scn = queueScenario();
    $invoiceId = draftViaApi($scn);

    test()->actingAs($scn['frontOffice'], 'sanctum')->deleteJson("/api/v1/invoices/{$invoiceId}")->assertStatus(405);
    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/invoices/{$invoiceId}/mark-paid")->assertNotFound();
    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/invoices/{$invoiceId}/payment")->assertNotFound();
    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/invoices/{$invoiceId}/receipt")->assertNotFound();
});
