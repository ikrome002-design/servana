<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('ui10', 'branch-experience');

it('returns a truthful assigned-branch dashboard without foreign-branch or gated fabrication', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Westlands Studio',
        'code' => 'WST',
        'address' => 'Woodvale Grove',
        'town' => 'Nairobi',
        'phone' => '+254700000001',
    ]);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    Service::factory()->count(2)->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);
    Service::factory()->count(3)->create(['merchant_id' => $merchant->id, 'branch_id' => $otherBranch->id]);
    BranchDayRecord::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'business_date' => businessToday()->toDateString(),
        'status' => BranchDayStatus::Open,
        'queue_is_open' => true,
    ]);
    Invoice::factory()->issued(100_000)->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'status' => InvoiceStatus::PartiallyPaid,
        'validated_paid_minor' => 40_000,
    ]);
    Invoice::factory()->issued(900_000)->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $otherBranch->id,
        'status' => InvoiceStatus::PartiallyPaid,
        'validated_paid_minor' => 800_000,
    ]);

    $response = $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}/dashboard")
        ->assertOk()
        ->assertJsonPath('data.overview.branch.id', $branch->ulid)
        ->assertJsonPath('data.overview.branch.name', 'Westlands Studio')
        ->assertJsonPath('data.overview.day.status', 'open')
        ->assertJsonPath('data.overview.day.queue_is_open', true)
        ->assertJsonPath('data.overview.services.total', 2)
        ->assertJsonPath('data.overview.financial.validated_revenue_by_currency.0.currency', 'KES')
        ->assertJsonPath('data.overview.financial.validated_revenue_by_currency.0.amount_minor', 40_000)
        ->assertJsonPath('data.overview.reporting.available', false)
        ->assertJsonPath('data.overview.billing.payment_runtime.available', false)
        ->assertJsonPath('data.overview.notifications.available', false);

    expect((string) $response->getContent())
        ->toContain('External Gate W')
        ->not->toContain($otherBranch->ulid)
        ->not->toContain('payment_success')
        ->not->toContain('notification_count');
});

it('serves paginated narrow financial and masked audit projections without client or payment-reference data', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $invoice = Invoice::factory()->issued(250_000)->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
    ]);
    $invoice->client()->update([
        'full_name' => 'Amina Private',
        'phone_encrypted' => '+254711111111',
        'phone_last_four' => '1111',
    ]);
    $foreignInvoice = Invoice::factory()->issued()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $otherBranch->id,
    ]);
    $payment = PaymentRecordingGroup::factory()->pendingValidation()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'invoice_id' => $invoice->id,
    ]);
    PaymentRecordingGroup::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $otherBranch->id,
        'invoice_id' => $foreignInvoice->id,
    ]);
    $event = AuditLog::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'actor_label' => 'operator@example.test',
        'context' => ['email' => 'client@example.test', 'reference' => 'SECRET-REFERENCE-1234'],
    ]);
    $foreignEvent = AuditLog::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $otherBranch->id,
    ]);

    $invoiceResponse = $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}/financial-visibility/invoices?per_page=25&sort=-created_at")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $invoice->ulid)
        ->assertJsonPath('data.0.can.create', false)
        ->assertJsonMissingPath('data.0.client');

    $paymentResponse = $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}/financial-visibility/payment-records?per_page=25")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $payment->ulid)
        ->assertJsonPath('data.0.can.validate', false)
        ->assertJsonMissingPath('data.0.records')
        ->assertJsonMissingPath('data.0.maker');

    $auditResponse = $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}/audit-events?per_page=25")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $event->ulid);

    expect((string) $invoiceResponse->getContent())
        ->not->toContain('Amina Private')
        ->not->toContain('+254711111111');
    expect((string) $paymentResponse->getContent())
        ->not->toContain('masked_reference')
        ->not->toContain('reference_tail')
        ->not->toContain('SECRET-REFERENCE-1234');
    expect((string) $auditResponse->getContent())
        ->not->toContain('operator@example.test')
        ->not->toContain('client@example.test')
        ->not->toContain('SECRET-REFERENCE-1234')
        ->not->toContain($foreignEvent->ulid);
});

it('fails closed for unassigned, foreign-tenant and wrong-account branch projections', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $assigned = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $unassigned = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $assigned, MerchantUserRole::BranchManager);
    [$hr] = branchStaff($merchant, $assigned, MerchantUserRole::Hr);

    $foreignMerchant = Merchant::factory()->active()->create();
    $foreignBranch = MerchantBranch::factory()->create(['merchant_id' => $foreignMerchant->id]);

    foreach (['dashboard', 'financial-visibility/invoices', 'financial-visibility/payment-records', 'audit-events'] as $path) {
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/branches/{$unassigned->ulid}/{$path}")
            ->assertForbidden();
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/branches/{$foreignBranch->ulid}/{$path}")
            ->assertNotFound();
    }

    $this->actingAs($hr, 'sanctum')
        ->getJson("/api/v1/branches/{$assigned->ulid}/dashboard")
        ->assertForbidden();
});

it('bounds every new collection and preserves Branch maker-checker and mutation denials', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);
    [, , $staff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);
    $invoice = Invoice::factory()->issued()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);
    $payment = PaymentRecordingGroup::factory()->pendingValidation()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'invoice_id' => $invoice->id,
    ]);
    $cashUp = BranchCashUp::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'business_date' => businessToday()->toDateString(),
    ]);

    foreach (['financial-visibility/invoices', 'financial-visibility/payment-records', 'audit-events'] as $path) {
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/branches/{$branch->ulid}/{$path}?per_page=101")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    }

    $this->actingAs($manager, 'sanctum')->postJson('/api/v1/invoices', [])->assertForbidden();
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/payment-recording-groups/{$payment->ulid}/validate", [])
        ->assertForbidden();
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/staff/{$staff->ulid}/suspend", [])
        ->assertForbidden();
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/cash-ups/{$cashUp->ulid}/approve", [])
        ->assertForbidden();
});
