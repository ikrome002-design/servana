<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Role;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions');

/*
 | The permission matrix, transcribed INDEPENDENTLY from the Plan (not from
 | PermissionRegistry) so this test is a genuine spec check: the seeded
 | role_permission_assignments must equal these cells exactly, with zero
 | mismatches. Interpretation per the Plan: any non-empty, non-`◐` cell is a
 | default grant; `◐` cells are grantable overrides (NOT default grants and so
 | absent here); personnel exports are `**never**` (no export key at all).
 |
 | Phase 15A reconciled the catalogue/eligibility/client cells to the CANONICAL
 | §19.2/§19.3 keys (its owning-phase contribution per §19.1): Branch Manager
 | `service.view/create/update/archive` (was `services.manage`); HR
 | `personnel.eligibility.manage` (was `eligibility.manage`); Front Office
 | `client.view/create/update` + `front_office.search` (was `clients.*`). Per
 | §19.3 `client.view` defaults to Front Office only, so the unwired legacy
 | `clients.view` grants on the other roles were dropped in the reconciliation
 | (full §10.3→§19 closure remains Phase 19 / REM-PERM-001).
 |
 | Phase 16B reconciled the QUEUE cells to the canonical §19/§37 keys: the legacy
 | Branch Manager `queue.operate`/`queue.transfer_entries`/`queue.configure` grants
 | were REMOVED (Branch Manager configures the queue via `branch.profile.manage` +
 | `day.open_close` and reads via `branch.dashboard.view` — no operational queue
 | key). Front Office gained `queue.view/create/assign/transfer/reorder` +
 | `preferred_personnel.select` (replacing the legacy `queue.operate`); Personnel
 | gained own-scope `personnel.my_queue.view`. REM-PERM-001 stays open (Phase 19).
 */
function expectedMatrix(): array
{
    return [
        'merchant_admin' => [
            'merchant.profile.manage',
            // Phase 20B: subscription self-service (replaces the retired `merchant.tier.update`).
            'merchant.subscription.view', 'merchant.subscription.plan_change',
            'merchant.subscription.invoice.view', 'merchant.subscription.invoice.download',
            'branches.create', 'branches.manage_users_lifecycle',
            // Phase 17: no invoice key (Plan §10.2/§19.3) — invoice visibility via reports.
            'receipt.view',
            // Phase 18B: routine period locking is Finance-owned (ADR-0007); the Merchant
            // Administrator holds ONLY exceptional-reopen approval (was legacy `periods.lock`).
            'merchant.period_reopen.approve_exception',
            // Phase 20F: legacy `commissions.view` RETIRED — the Merchant Administrator never
            // configures commissions (Plan §10.2) and gets NO replacement read here; compensation
            // visibility arrives as merchant.compensation_summary.view in Phase 20H.
            // Phase 20E: canonical merchant-wide platform-fee read + dispute creation.
            'platform_fee.view', 'platform_fee.dispute',
            // Phase 20H: the Merchant Administrator's compensation surface — the masked, currency-grouped
            // summary read + high-value payout approval ONLY (Plan §10.2/§62; no create/verify/standard-
            // approve/mark-paid — those stay HR/Finance).
            'merchant.compensation_summary.view', 'merchant.payout.approve_high_value',
            // Phase 19: `audit.view_full` RETIRED — Merchant Admin holds NO direct raw
            // audit-log key (canonical §19.3; oversight via reports/dashboards).
            'reports.view',
        ],
        'branch_manager' => [
            'branch.profile.manage', 'branch.calendar.manage', 'branch.dashboard.view',
            'service.view', 'service.create', 'service.update', 'service.archive',
            // Phase 18B: canonical cash-up maker key (was legacy `cashup.submit`).
            'day.open_close', 'branch.cash_up.submit',
            // Phase 17: NO invoice key — Branch Manager must not create invoices
            // (Plan §10.2/§19.3); legacy invoices.create/view grants removed.
            // Phase 20E: branch-attributable masked platform-fee read only.
            // Phase 20F: legacy `commissions.view` RETIRED — no compensation authority, no replacement.
            'receipt.view', 'platform_fee.view',
            // Phase 19: `audit.view_full` RETIRED — no direct raw audit-log key.
            'reports.view',
            // Phase 20A: read-only effective preferred-personnel fee rule for the branch.
            'preferred_personnel_fee.view_branch_rule',
        ],
        'hr' => [
            'staff.invite', 'staff.edit', 'staff.suspend',
            'personnel.eligibility.manage', 'personnel.availability.manage',
            // Phase 20F: HR owns compensation CONFIGURATION end to end (canonical successors of the
            // retired commissions.manage / commissions.view).
            'compensation.plan.view', 'compensation.plan.create', 'compensation.plan.update_draft',
            'compensation.plan.submit', 'compensation.plan.approve', 'compensation.plan.reject',
            'compensation.plan.cancel', 'compensation.history.view',
            // Phase 20H: HR owns the payout-run maker workflow (branch-scoped) — draft/update/
            // submit(freeze)/cancel ONLY; HR never verifies, approves, or marks paid (Plan §10.2/§62).
            'payout_run.create', 'payout_run.update_draft', 'payout_run.submit', 'payout_run.cancel_draft',
            // Phase 19: `audit.view_full` RETIRED — no direct raw audit-log key.
            'reports.view',
            'exports.staff_roster',
        ],
        'finance' => [
            'invoice.view', 'invoice.void.request_or_execute_as_policy', 'invoice.adjustment.manage',
            'customer_payment.view', 'customer_payment.duplicate_override', 'customer_payment.record_exception',
            'customer_payment.validate', 'customer_payment.reject', 'customer_payment.reference_correct',
            'receipt.view', 'receipt.reissue', 'refund.create', 'finance_dispute.manage',
            // Phase 18B canonical cash-up checker + period-lock + finance-export keys
            // (were legacy `cashup.review_approve`; period_lock.* reconciled from the
            // legacy Finance-grantable `periods.reopen`; finance_export.* from `exports.finance`).
            'cash_up.view', 'cash_up.approve', 'cash_up.reject', 'cash_up.request_correction',
            'period_lock.create', 'period_lock.reopen',
            'finance_export.create', 'finance_export.download',
            // Phase 20E: settled/reconciliation read + dispute creation + dispute review/resolve/reject.
            'platform_fee.view', 'platform_fee.dispute', 'platform_fee.dispute.review',
            // Phase 19: canonical Finance audit surface (REPLACES legacy `audit.view_full`).
            'reports.view', 'finance.audit.view',
            // Phase 20G — Finance compensation financial surface (merchant scope).
            'compensation.liability.view', 'compensation.adjustment.create',
            // Phase 20H — Finance payout checker workflow + earnings-query resolution (Plan §62/§63):
            // verify/standard-approve/reject/mark-paid and earnings_query.respond. Mark-paid records an
            // EXTERNAL settlement only — no money movement, no Wallet/provider call.
            'payout_run.verify', 'payout_run.approve_standard', 'payout_run.reject', 'payout_run.mark_paid',
            'earnings_query.respond',
        ],
        'front_office' => [
            'queue.view', 'queue.create', 'queue.assign', 'queue.transfer', 'queue.reorder',
            'preferred_personnel.select',
            'appointment.view', 'appointment.create', 'appointment.reschedule',
            'appointment.cancel', 'appointment.check_in', 'appointment.assign',
            'appointment.transfer',
            'client.view', 'client.create', 'client.update', 'front_office.search',
            'service_session.view', 'service_session.start',
            'service_session.complete', 'service_session.cancel',
            'invoice.view', 'invoice.create',
            'customer_payment.record', 'receipt.view', 'reports.view',
        ],
        'personnel' => [
            'personnel.my_appointments.view', 'personnel.my_queue.view',
            'personnel.my_sessions.view',
            // Phase 17: no invoice key (strict own-scope; no broad browsing).
            'receipt.view',
            // Phase 20F: legacy `commissions.view` RETIRED — Personnel never see compensation
            // configuration; own-earnings visibility arrives with Phase 20H below.
            'reports.view',
            // Phase 20H — Personnel own-scope compensation/earnings surface (Plan §62/§63): own
            // compensation terms, earnings overview, payout history, own paid-period statement
            // download, and raising an earnings query against an own fact. Strict own-scope only.
            'personnel.my_compensation.view', 'personnel.my_earnings.view',
            'personnel.my_statements.download', 'personnel.my_payouts.view',
            'personnel.my_earnings_query.create',
        ],
        'audit' => [
            // Phase 17: no invoice key (Audit reads finance activity via the finance-domain audit view).
            'receipt.view',
            // Phase 20F: legacy `commissions.view` RETIRED — Audit reads the compensation domain
            // through the masked audit.compensation.view below (populated by Phase 20F).
            'platform_fee.view', 'reports.view',
            // Phase 19 — canonical, domain-segmented, branch-scoped, masked Audit reads
            // (REPLACE the retired catch-all `audit.view_full`) + the flagged-event review
            // workflow (review metadata only; Audit stays read-only over source records).
            'audit.branch_events.view', 'audit.finance.view', 'audit.compensation.view',
            // Phase 19 (ADR-010): Audit's in-domain export capability (request + download; SU Y).
            'audit.export',
            'audit.flagged_event.create', 'audit.flagged_event.update_status', 'audit.flagged_event.resolve_metadata',
        ],
        'super_admin' => [
            // Phase 20B — merchant governance (replaces the retired `platform.merchants.govern`,
            // truthfully split into a read surface + three operational-status mutations).
            'platform.registration_monitor.view', 'platform.merchant.view',
            'platform.merchant.suspend', 'platform.merchant.reactivate', 'platform.merchant.deactivate',
            'platform.audit.view',
            // Phase 20A — platform billing catalogue governance (replaces the retired
            // platform.settings.manage / platform.billing.configure / platform.fee_rules.manage).
            'platform.settings.view', 'platform.settings.update',
            'platform.billing_settings.view', 'platform.billing_settings.update',
            'platform.plan.view', 'platform.plan.manage',
            'platform.plan_price.manage', 'platform.preferred_personnel_fee.manage',
            // Phase 20C — promotions & free-period offers (Plan §53).
            'platform.promotion.manage', 'platform.free_period_offer.manage',
            // Phase 20E — percentage platform-fee configuration governance (Plan §51/§52).
            'platform.platform_fee.configure',
        ],
    ];
}

it('seeds the §10.3 matrix with zero mismatches', function (): void {
    $this->seed(PermissionSeeder::class);

    $allKeys = Permission::query()->pluck('key')->sort()->values()->all();
    $mismatches = [];

    foreach (expectedMatrix() as $roleKey => $expectedKeys) {
        /** @var Role $role */
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $seeded = $role->permissions()->pluck('key')->all();

        // Iterate EVERY (role, permission) cell — both grants and non-grants.
        foreach ($allKeys as $key) {
            $shouldGrant = in_array($key, $expectedKeys, true);
            $isGranted = in_array($key, $seeded, true);

            if ($shouldGrant !== $isGranted) {
                $mismatches[] = sprintf('%s × %s expected=%s seeded=%s', $roleKey, $key, $shouldGrant ? 'grant' : 'none', $isGranted ? 'grant' : 'none');
            }
        }
    }

    expect($mismatches)->toBe([], implode("\n", $mismatches));
});

it('matches the canonical PermissionRegistry (DB == registry)', function (): void {
    $this->seed(PermissionSeeder::class);
    $registry = app(PermissionRegistry::class);

    foreach ($registry->roleKeys() as $roleKey) {
        /** @var Role $role */
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $seeded = $role->permissions()->pluck('key')->sort()->values()->all();
        $expected = collect($registry->defaultGrantsFor($roleKey))->sort()->values()->all();

        expect($seeded)->toBe($expected, "role {$roleKey} default grants drifted from the registry");
    }
});

it('writes the matrix proof artifact', function (): void {
    $this->seed(PermissionSeeder::class);

    $roles = Role::query()->orderBy('id')->pluck('key')->all();
    $permissions = Permission::query()->orderBy('category')->orderBy('key')->get();

    $lines = ['Servana — Phase 8 permission matrix (seeded §10.3). ✓ = default grant.', ''];
    $header = str_pad('permission', 38).implode('  ', array_map(static fn ($r): string => substr($r, 0, 12), $roles));
    $lines[] = $header;
    $lines[] = str_repeat('-', strlen($header));

    foreach ($permissions as $permission) {
        $row = str_pad($permission->key, 38);
        foreach ($roles as $roleKey) {
            /** @var Role $role */
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $granted = $role->permissions()->where('key', $permission->key)->exists();
            $row .= str_pad($granted ? '✓' : '·', 14);
        }
        $lines[] = rtrim($row);
    }

    $path = base_path('docs/proof/phase8-matrix.txt');
    file_put_contents($path, implode("\n", $lines)."\n");

    expect(file_exists($path))->toBeTrue();
})->group('proof');
