<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Actions\GenerateSubscriptionInvoicePdf;
use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Services\SubscriptionInvoiceDocumentRenderer;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('billing', 'phase20b-invoice-pdf', 'subscription-invoice');

function p20bpdfBoot(): void
{
    Storage::fake((string) config('files.disk'));
    PlatformBillingSettings::factory()->create([
        'billing_mode' => BillingMode::FixedAmount,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);
}

/** Issue an invoice for a fresh active merchant, tenant context bound. */
function p20bpdfInvoice(): SubscriptionInvoice
{
    $merchant = Merchant::factory()->create(['billing_status' => MerchantBillingStatus::Active]);
    app(TenantContext::class)->bindForJob($merchant);
    $plan = SubscriptionPlan::factory()->create();
    $price = SubscriptionPlanPrice::factory()->create(['plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES', 'amount_minor' => 500000]);
    $sub = MerchantSubscription::factory()->forMerchant($merchant)->status(S::Active)->create([
        'plan_id' => $plan->id, 'price_id' => $price->id, 'billing_interval' => 'monthly',
        'current_period_start' => '2026-07-01', 'current_period_end' => '2026-08-01',
    ]);

    return app(IssueSubscriptionInvoice::class)->handle($sub, User::factory()->create());
}

function p20bpdfSetBilling(SubscriptionInvoice $invoice, MerchantBillingStatus $status): void
{
    Merchant::query()->whereKey($invoice->merchant_id)->update(['billing_status' => $status->value]);
    app(TenantContext::class)->bindForJob(Merchant::query()->whereKey($invoice->merchant_id)->first());
}

it('generates a private billing_invoice_pdf associated with the invoice and merchant', function (): void {
    p20bpdfBoot();
    $invoice = p20bpdfInvoice();

    $result = app(GenerateSubscriptionInvoicePdf::class)->handle($invoice, User::factory()->create());

    $result->refresh();
    $file = $result->file()->first();
    expect($result->file_id)->not->toBeNull()
        ->and($result->pdf_version)->toBe(1)
        ->and($file->purpose->value)->toBe('billing_invoice_pdf')
        ->and($file->purpose)->toBe(FilePurpose::BillingInvoicePdf)
        ->and($file->merchant_id)->toBe($invoice->merchant_id)
        ->and($file->branch_id)->toBeNull();
});

it('renders the exact pending-reference text and no account reference while unregistered', function (): void {
    p20bpdfBoot();
    $invoice = p20bpdfInvoice();

    $bytes = app(SubscriptionInvoiceDocumentRenderer::class)->render($invoice);

    // MinimalPdf is ASCII-only (em-dash → space, matching the receipt precedent); assert the ASCII
    // prefix/suffix are present, and the exact canonical constant used by the API/UI.
    expect($bytes)->toContain('Payment reference pending')
        ->and($bytes)->toContain('see your billing dashboard')
        ->and(SubscriptionInvoiceDocumentRenderer::PENDING_REFERENCE_TEXT)->toBe('Payment reference pending — see your billing dashboard')
        ->and($invoice->account_reference)->toBeNull()
        ->and($bytes)->not->toContain('SRV-PAY');
});

it('allows generation while active, trialing, and overdue', function (): void {
    p20bpdfBoot();
    foreach ([MerchantBillingStatus::Active, MerchantBillingStatus::Trialing, MerchantBillingStatus::Overdue] as $status) {
        $invoice = p20bpdfInvoice();
        p20bpdfSetBilling($invoice, $status);
        $result = app(GenerateSubscriptionInvoicePdf::class)->handle($invoice, User::factory()->create());
        expect($result->file_id)->not->toBeNull();
    }
});

it('blocks new generation in read_only_grace and suspended_billing (403 billing_read_only)', function (): void {
    p20bpdfBoot();
    foreach ([MerchantBillingStatus::ReadOnlyGrace, MerchantBillingStatus::SuspendedBilling] as $status) {
        $invoice = p20bpdfInvoice();
        p20bpdfSetBilling($invoice, $status);
        try {
            app(GenerateSubscriptionInvoicePdf::class)->handle($invoice, User::factory()->create());
            test()->fail('Expected billing_read_only rejection.');
        } catch (TenantAccessException $e) {
            expect($e->render(Request::create('/'))->getStatusCode())->toBe(403);
        }
    }
});

it('creates a new version and revokes the previous file on regeneration', function (): void {
    p20bpdfBoot();
    $invoice = p20bpdfInvoice();
    $actor = User::factory()->create();

    $first = app(GenerateSubscriptionInvoicePdf::class)->handle($invoice, $actor);
    $firstFileId = $first->file_id;
    $second = app(GenerateSubscriptionInvoicePdf::class)->handle($invoice->fresh(), $actor);

    expect($second->pdf_version)->toBe(2)
        ->and($second->file_id)->not->toBe($firstFileId)
        ->and(UploadedFile::query()->whereKey($firstFileId)->value('lifecycle_status'))->toBe(FileLifecycleStatus::Revoked);
});

it('leaves the issued financial snapshot unchanged after PDF generation', function (): void {
    p20bpdfBoot();
    $invoice = p20bpdfInvoice();
    $before = $invoice->only(['total_minor', 'subtotal_minor', 'invoice_number', 'currency', 'status']);

    app(GenerateSubscriptionInvoicePdf::class)->handle($invoice, User::factory()->create());

    expect($invoice->fresh()->only(['total_minor', 'subtotal_minor', 'invoice_number', 'currency', 'status']))->toBe($before);
});

it('emits exactly one subscription_invoice.pdf_generated audit event per generation', function (): void {
    p20bpdfBoot();
    $invoice = p20bpdfInvoice();

    app(GenerateSubscriptionInvoicePdf::class)->handle($invoice, User::factory()->create());

    expect(DB::table('audit_logs')->where('action', AuditEvent::SubscriptionInvoicePdfGenerated->value)->where('merchant_id', $invoice->merchant_id)->count())->toBe(1);
});

it('introduces no Wallet runtime while generating a PDF', function (): void {
    p20bpdfBoot();
    $invoice = p20bpdfInvoice();
    app(GenerateSubscriptionInvoicePdf::class)->handle($invoice, User::factory()->create());

    foreach (['subscription_payments', 'wallet_webhook_inbox', 'wallet_merchant_account_links'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect($invoice->fresh()->wallet_registration_status->value)->toBe('unregistered');
});
