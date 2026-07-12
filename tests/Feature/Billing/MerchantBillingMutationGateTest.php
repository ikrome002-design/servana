<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\EnsureBillingMutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class)->group('billing', 'phase20b-billing-gate', 'billing-status');

function p20bgGate(MerchantBillingStatus $status): EnsureBillingMutable
{
    $merchant = Merchant::factory()->create();
    $merchant->billing_status = $status;
    $merchant->save();

    $context = app(TenantContext::class);
    $context->bindForJob($merchant->fresh());

    return new EnsureBillingMutable($context);
}

function p20bgPass(EnsureBillingMutable $gate): string
{
    return (string) $gate->handle(Request::create('/api/v1/merchant/thing', 'POST'), fn () => response('ok'))->getContent();
}

it('allows mutations while trialing', function (): void {
    expect(p20bgPass(p20bgGate(MerchantBillingStatus::Trialing)))->toBe('ok');
});

it('allows mutations while active', function (): void {
    expect(p20bgPass(p20bgGate(MerchantBillingStatus::Active)))->toBe('ok');
});

it('allows mutations while overdue (grace still open, Plan §25.2)', function (): void {
    expect(p20bgPass(p20bgGate(MerchantBillingStatus::Overdue)))->toBe('ok');
});

it('blocks mutations in read_only_grace with billing_read_only', function (): void {
    try {
        p20bgPass(p20bgGate(MerchantBillingStatus::ReadOnlyGrace));
        $this->fail('Expected a billing_read_only rejection.');
    } catch (TenantAccessException $e) {
        expect($e->render(Request::create('/'))->getStatusCode())->toBe(403);
    }
});

it('blocks mutations in suspended_billing with billing_read_only', function (): void {
    try {
        p20bgPass(p20bgGate(MerchantBillingStatus::SuspendedBilling));
        $this->fail('Expected a billing_read_only rejection.');
    } catch (TenantAccessException $e) {
        expect($e->render(Request::create('/'))->getStatusCode())->toBe(403);
    }
});

it('reads only billing_status — blocks blocking states regardless of operational status', function (): void {
    // A merchant model helper drives the gate; it consults billing_status, never subscription status.
    $merchant = Merchant::factory()->create();
    $merchant->billing_status = MerchantBillingStatus::SuspendedBilling;
    $merchant->save();

    expect($merchant->billingBlocksMutations())->toBeTrue();

    $merchant->billing_status = MerchantBillingStatus::Active;
    $merchant->save();
    expect($merchant->billingBlocksMutations())->toBeFalse();
});
