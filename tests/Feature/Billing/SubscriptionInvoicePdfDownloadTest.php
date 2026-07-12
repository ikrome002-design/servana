<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\GenerateSubscriptionInvoicePdf;
use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Files\Services\FileAccessService;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class)->group('billing', 'phase20b-invoice-pdf', 'subscription-invoice');

/** @return array{0:Merchant,1:User,2:UploadedFile} merchant, its admin user, generated PDF file. */
function p20bpddInvoiceFile(): array
{
    Storage::fake((string) config('files.disk'));
    PlatformBillingSettings::factory()->create(['billing_mode' => BillingMode::FixedAmount, 'effective_from' => CarbonImmutable::now()->subYear()]);

    $user = User::factory()->create();
    $merchant = Merchant::factory()->create(['billing_status' => MerchantBillingStatus::Active]);
    app(TenantContext::class)->bindForJob($merchant);
    $plan = SubscriptionPlan::factory()->create();
    $price = SubscriptionPlanPrice::factory()->create(['plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES', 'amount_minor' => 500000]);
    $sub = MerchantSubscription::factory()->forMerchant($merchant)->status(S::Active)->create([
        'plan_id' => $plan->id, 'price_id' => $price->id, 'billing_interval' => 'monthly',
        'current_period_start' => '2026-07-01', 'current_period_end' => '2026-08-01',
    ]);
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, $user);
    $result = app(GenerateSubscriptionInvoicePdf::class)->handle($invoice, $user);

    return [$merchant, $user, $result->file()->first()];
}

it('authorizes download of a generated invoice PDF for the owning merchant', function (): void {
    [, $user, $file] = p20bpddInvoiceFile();

    app(FileAccessService::class)->authorizeDownload($file, $user); // no throw
    $link = app(FileAccessService::class)->issueSignedUrl($file);

    expect($link)->toHaveKeys(['url', 'expires_at'])
        ->and($link['url'])->toContain('signature=');
});

it('keeps an existing PDF downloadable in read_only_grace and suspended_billing', function (): void {
    [$merchant, $user, $file] = p20bpddInvoiceFile();

    foreach ([MerchantBillingStatus::ReadOnlyGrace, MerchantBillingStatus::SuspendedBilling] as $status) {
        $merchant->update(['billing_status' => $status]);
        app(TenantContext::class)->bindForJob($merchant->fresh());
        app(FileAccessService::class)->authorizeDownload($file, $user); // no throw — billing state is not a download gate
    }
    expect(true)->toBeTrue();
});

it('denies cross-tenant download of an invoice PDF (404, no existence leak)', function (): void {
    [, , $file] = p20bpddInvoiceFile();

    $otherMerchant = Merchant::factory()->create();
    $otherUser = User::factory()->create();
    app(TenantContext::class)->bindForJob($otherMerchant);

    expect(fn () => app(FileAccessService::class)->authorizeDownload($file, $otherUser))
        ->toThrow(NotFoundHttpException::class);
});

it('denies download of a revoked (superseded) PDF version', function (): void {
    [$merchant, $user, $file] = p20bpddInvoiceFile();
    $file->markLifecycle(FileLifecycleStatus::Revoked);
    app(TenantContext::class)->bindForJob($merchant->fresh());

    expect(fn () => app(FileAccessService::class)->authorizeDownload($file->fresh(), $user))
        ->toThrow(NotFoundHttpException::class);
});
