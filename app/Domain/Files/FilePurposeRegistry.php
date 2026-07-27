<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Files\Enums\FilePurpose;

/**
 * Central, version-controlled file-purpose policy registry (Plan §65; Phase 10F).
 *
 * Single source of truth for what each purpose allows: uploadable vs generated-only,
 * owning phase, allowed extensions / detected MIME types, byte ceiling, tenant /
 * branch / owner requirements, the required (existing) permission, image
 * sanitisation, retention, and the billing-read-only generation rule.
 *
 * Only existing permission keys are used (no speculative permissions). At Phase 10F
 * only `merchant_logo` and `profile_photo` are uploadable; every export/PDF/report/
 * statement purpose is generated-only (client upload prohibited) and its real
 * generator is attached by the owning phase.
 */
final class FilePurposeRegistry
{
    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'webp'];

    private const IMAGE_MIME = ['image/png', 'image/jpeg', 'image/webp'];

    /** @var array<string, FilePurposeDefinition>|null */
    private static ?array $cache = null;

    public static function for(FilePurpose $purpose): FilePurposeDefinition
    {
        return self::all()[$purpose->value];
    }

    /** @return array<string, FilePurposeDefinition> */
    public static function all(): array
    {
        return self::$cache ??= self::build();
    }

    /** @return list<FilePurpose> purposes a client may upload (active this phase). */
    public static function uploadablePurposes(): array
    {
        return array_values(array_map(
            static fn (FilePurposeDefinition $d): FilePurpose => $d->purpose,
            array_filter(self::all(), static fn (FilePurposeDefinition $d): bool => $d->uploadable),
        ));
    }

    public static function isUploadable(FilePurpose $purpose): bool
    {
        return self::for($purpose)->uploadable;
    }

    /** @return array<string, FilePurposeDefinition> */
    private static function build(): array
    {
        $imageMax = (int) config('files.image_max_bytes', 5 * 1024 * 1024);
        $exportRetention = (int) config('files.export_retention_days', 30);

        $defs = [];

        // --- Active uploadable image purposes (Phase 10F) ---------------------
        // REM-SCR-002A retired the legacy `merchant.profile.manage` and activated the canonical
        // §19.3 pair. Uploading a logo IS a merchant-profile write, so this purpose moves to
        // `merchant.profile.update` — the same Merchant Administrator authority, canonically named.
        $defs[] = new FilePurposeDefinition(
            FilePurpose::MerchantLogo, true, '10F', self::IMAGE_EXT, self::IMAGE_MIME,
            $imageMax, true, false, false, 'merchant.profile.update', true, null, false,
        );
        $defs[] = new FilePurposeDefinition(
            FilePurpose::ProfilePhoto, true, '10F', self::IMAGE_EXT, self::IMAGE_MIME,
            $imageMax, true, false, false, 'staff.edit', true, null, false,
        );

        // --- Evidence purposes: enum/schema supported, no upload exposure yet --
        $defs[] = new FilePurposeDefinition(
            FilePurpose::DisputeEvidence, false, '18B/19', [], [], $imageMax, true, true, false, 'finance_dispute.manage', false, null, false,
        );
        $defs[] = new FilePurposeDefinition(
            FilePurpose::AuditEvidence, false, '19', [], [], $imageMax, true, false, false, 'audit.branch_events.view', false, null, false,
        );

        // --- Generated-only export / PDF / report / statement purposes --------
        // Client upload prohibited; the owning phase attaches the generator and
        // (where billing applies) the billing-read-only rule blocks NEW generation.
        $defs[] = new FilePurposeDefinition(
            FilePurpose::FinanceExport, false, '18B/23', [], [], 0, true, false, false, 'finance_export.download', false, $exportRetention, true,
        );
        // Audit export (Phase 19; ADR-010): generated-only, branch-scoped, permission
        // `audit.export`. Download authority is the Audit read/export key; the private
        // CSV is written by GenerateAuditExport through GeneratedFileWriter.
        $defs[] = new FilePurposeDefinition(
            FilePurpose::AuditExport, false, '19', [], [], 0, true, false, false, 'audit.export', false, $exportRetention, true,
        );
        $defs[] = new FilePurposeDefinition(
            FilePurpose::InvoicePdf, false, '17', [], [], 0, true, true, false, 'invoice.view', false, null, true,
        );
        $defs[] = new FilePurposeDefinition(
            FilePurpose::ReceiptPdf, false, '18', [], [], 0, true, true, false, 'receipt.view', false, null, true,
        );
        // Billing invoice PDF (Phase 20B): a merchant-scope financial document. PH23-EXP-002 —
        // this purpose carried NO resource permission, so tenant membership alone authorised the
        // generic file routes and any member (Front Office, Personnel, …) could pull the
        // subscription invoice the domain route reserves for the Merchant Administrator. Plan §65
        // requires "resource permission" in download authorization; the key already exists.
        $defs[] = new FilePurposeDefinition(
            FilePurpose::BillingInvoicePdf, false, '20A/20B', [], [], 0, true, false, false,
            'merchant.subscription.invoice.download', false, null, true,
        );
        // Personnel earnings statement: own-scope is the authority (owner_user_id
        // must equal the caller). No extra permission key; billing read-only blocks
        // new generation but never an existing authorized download.
        $defs[] = new FilePurposeDefinition(
            FilePurpose::EarningsStatement, false, '20H/21N', [], [], 0, true, false, true, null, false, $exportRetention, true,
        );
        $defs[] = new FilePurposeDefinition(
            FilePurpose::DayCloseReport, false, '18B', [], [], 0, true, true, false, 'reports.view', false, $exportRetention, true,
        );
        $defs[] = new FilePurposeDefinition(
            FilePurpose::CashUpReport, false, '18B', [], [], 0, true, true, false, 'reports.view', false, $exportRetention, true,
        );

        $map = [];
        foreach ($defs as $def) {
            $map[$def->purpose->value] = $def;
        }

        return $map;
    }
}
