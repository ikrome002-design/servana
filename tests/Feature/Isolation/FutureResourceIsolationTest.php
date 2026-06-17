<?php

declare(strict_types=1);

uses()->group('isolation', 'tenancy');

/*
 | Placeholders for the §8.4 denied-case rows whose resources do not exist yet.
 | Each is a permanent, explicitly-skipped test naming the owning phase, so the
 | isolation suite enumerates every §8.4 row and the future phase has a failing
 | (skipped → unskip) test waiting. The tenancy traits + scoped binding built in
 | Phase 9 apply automatically once these models gain BelongsToMerchant/Branch.
 */

it('GET /invoices/{ulid-of-other-merchant} → 404 + audit unauthorized_access', function (): void {
    // Owning phase: 17 (Invoicing). Invoice model will use BelongsToMerchant +
    // BelongsToBranch; RouteBindingTest's dataset gains the invoice case then.
})->skip('Invoices arrive in Phase 17 (Plan §8.4 InvoiceCrossTenantTest).');

it('Finance of Branch X lists payments of Branch Y (same merchant) → empty/404 + audit', function (): void {
    // Owning phase: 18 (Payments). Payment model branch-scoped via BelongsToBranch.
})->skip('Payments arrive in Phase 18 (Plan §8.4 CrossBranchPaymentTest).');

it('Export job given an unscoped query → export service refuses', function (): void {
    // Owning phase: 18/19 (finance exports / audit). ExportScope assertion lands
    // with the export service.
})->skip('Exports arrive in Phase 18/19 (Plan §8.4 ExportScopeTest).');

it('Personnel requests another personnel queue → 404', function (): void {
    // Owning phase: 16 (queue/sessions). Personnel own-scope enforced on the
    // queue/session models once they exist.
})->skip('Queue/sessions/personnel own-scope arrive in Phase 16 (Plan §8.4 PersonnelOwnScopeTest).');
