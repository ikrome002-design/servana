<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionRegistry;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\FilePurposeRegistry;

uses()->group('files');

/*
 | Central file-purpose policy registry (Plan §65; Phase 10F).
 */

it('defines every FilePurpose exactly once', function (): void {
    $defined = array_keys(FilePurposeRegistry::all());
    $cases = array_map(fn (FilePurpose $p): string => $p->value, FilePurpose::cases());

    sort($defined);
    sort($cases);

    expect($defined)->toBe($cases);
});

it('marks only merchant_logo and profile_photo as uploadable this phase', function (): void {
    $uploadable = array_map(fn (FilePurpose $p): string => $p->value, FilePurposeRegistry::uploadablePurposes());
    sort($uploadable);

    expect($uploadable)->toBe(['merchant_logo', 'profile_photo']);
});

it('keeps every export/PDF/report/statement purpose generated-only', function (): void {
    foreach ([
        FilePurpose::FinanceExport, FilePurpose::InvoicePdf, FilePurpose::ReceiptPdf,
        FilePurpose::BillingInvoicePdf, FilePurpose::EarningsStatement,
        FilePurpose::DayCloseReport, FilePurpose::CashUpReport,
    ] as $purpose) {
        expect(FilePurposeRegistry::for($purpose)->uploadable)->toBeFalse();
    }
});

it('requires image MIME types and sanitisation for uploadable image purposes', function (): void {
    foreach ([FilePurpose::MerchantLogo, FilePurpose::ProfilePhoto] as $purpose) {
        $def = FilePurposeRegistry::for($purpose);
        expect($def->sanitizeImage)->toBeTrue()
            ->and($def->mimeTypes)->toContain('image/png')
            ->and($def->maxBytes)->toBeGreaterThan(0)
            ->and($def->allowsMime('application/pdf'))->toBeFalse();
    }
});

it('only references existing permission keys (no speculative permissions)', function (): void {
    $known = app(PermissionRegistry::class)->permissionKeys();

    foreach (FilePurposeRegistry::all() as $def) {
        if ($def->permission !== null) {
            expect($known)->toContain($def->permission);
        }
    }
});

it('authorises the personnel earnings statement by own-scope (no extra permission)', function (): void {
    $def = FilePurposeRegistry::for(FilePurpose::EarningsStatement);

    expect($def->requiresOwner)->toBeTrue()
        ->and($def->permission)->toBeNull()
        ->and($def->billingReadOnlyGeneration)->toBeTrue();
});
