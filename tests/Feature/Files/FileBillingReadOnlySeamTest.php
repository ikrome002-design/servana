<?php

declare(strict_types=1);

use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\FileGenerationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('files');

it('denies generating a new billing-gated file while billing is read-only', function (): void {
    $policy = new FileGenerationPolicy;

    expect($policy->canGenerate(FilePurpose::FinanceExport, billingReadOnly: true))->toBeFalse()
        ->and($policy->canGenerate(FilePurpose::InvoicePdf, billingReadOnly: true))->toBeFalse()
        ->and($policy->canGenerate(FilePurpose::EarningsStatement, billingReadOnly: true))->toBeFalse();

    $this->expectException(DomainException::class);
    $policy->assertCanGenerate(FilePurpose::FinanceExport, billingReadOnly: true);
});

it('allows generation when billing access is not read-only', function (): void {
    $policy = new FileGenerationPolicy;

    expect($policy->canGenerate(FilePurpose::FinanceExport, billingReadOnly: false))->toBeTrue();
    // A non-billing purpose is never blocked by billing read-only.
    expect($policy->canGenerate(FilePurpose::MerchantLogo, billingReadOnly: true))->toBeTrue();
});

it('keeps an already-available authorized file downloadable regardless of billing state', function (): void {
    [$admin, $merchant] = activeAdmin();
    $file = availableFile($merchant->id, FilePurpose::MerchantLogo);

    // The download path never consults FileGenerationPolicy — existing files stand.
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/files/{$file->ulid}/download-link")
        ->assertOk();
});
