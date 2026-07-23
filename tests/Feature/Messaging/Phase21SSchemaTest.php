<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('messaging', 'sms', 'phase21s', 'schema');

/*
 | Phase 21S schema proof (Plan §13.13 canonical DDL; §64; ADR-010; ADR-005). Asserts the four
 | tables, their constraints, triggers, indexes and FKs against the LIVE PostgreSQL catalogue —
 | never against the migration source, so a migration that silently stops doing what it says fails
 | here.
 */

/** @return list<string> */
function p21sChecks(string $table): array
{
    /** @var list<string> $names */
    $names = DB::table('information_schema.table_constraints')
        ->where('table_name', $table)
        ->where('constraint_type', 'CHECK')
        ->pluck('constraint_name')
        ->all();

    return $names;
}

function p21sCheckClause(string $constraint): string
{
    return (string) DB::table('pg_constraint')
        ->where('conname', $constraint)
        ->selectRaw('pg_get_constraintdef(oid) as def')
        ->value('def');
}

/** @return list<string> */
function p21sTriggers(string $table): array
{
    /** @var list<string> $names */
    $names = DB::table('information_schema.triggers')
        ->where('event_object_table', $table)
        ->distinct()
        ->pluck('trigger_name')
        ->all();

    return $names;
}

/** @return list<string> */
function p21sIndexes(string $table): array
{
    /** @var list<string> $names */
    $names = DB::table('pg_indexes')->where('tablename', $table)->pluck('indexname')->all();

    return $names;
}

function p21sFkDeleteRule(string $table, string $constraint): string
{
    return (string) DB::table('information_schema.referential_constraints as rc')
        ->join('information_schema.table_constraints as tc', 'tc.constraint_name', '=', 'rc.constraint_name')
        ->where('tc.table_name', $table)
        ->where('rc.constraint_name', $constraint)
        ->value('rc.delete_rule');
}

it('creates exactly the four Phase 21S tables', function (): void {
    foreach (['personnel_sms_campaigns', 'personnel_sms_recipients', 'sms_delivery_attempts', 'sms_billing_entries'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} must exist");
    }
});

it('creates NO contact-export, Wallet, R&E-reward or future-phase table', function (): void {
    // ADR-010 + the phase scope boundary: these names must not exist anywhere in the schema.
    $forbidden = [
        // Contact export in any shape.
        'personnel_contact_exports', 'contact_exports', 'client_exports', 'sms_contact_exports',
        'personnel_sms_exports', 'served_client_exports',
        // Wallet runtime (20D-W, Gate W CLOSED).
        'wallet_payments', 'wallet_payment_attempts', 'wallet_webhook_inbox',
        'wallet_merchant_accounts', 'billing_reconciliation_exceptions',
        // R&E reward truth (never Servana) + Phase 21R-B.
        'reward_ledger', 'referrers', 're_campaigns', 're_rewards', 're_payouts',
        're_activity_rule_versions', 're_qualification_periods', 're_qualification_decisions',
        're_inbound_requests',
        // Later phases.
        'notifications', 'scheduled_report_runs',
    ];

    foreach ($forbidden as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} must NOT exist in Phase 21S");
    }
});

it('registers all four tables in TenantOwnership with the right classification', function (): void {
    expect(TenantOwnership::BRANCH_OWNED)->toContain('personnel_sms_campaigns')
        ->and(TenantOwnership::BRANCH_OWNED)->toContain('personnel_sms_recipients')
        ->and(TenantOwnership::BRANCH_OWNED)->toContain('sms_billing_entries')
        // Attempts inherit scope via recipient_id and are never route-bound.
        ->and(TenantOwnership::EXEMPT)->toHaveKey('sms_delivery_attempts');

    foreach (['personnel_sms_campaigns', 'personnel_sms_recipients', 'sms_billing_entries'] as $table) {
        expect(TenantOwnership::COMPOSITE_CONSISTENCY)->toHaveKey($table);
    }
});

it('gives personnel_sms_campaigns its columns, enum CHECK and immutability trigger', function (): void {
    foreach ([
        'ulid', 'merchant_id', 'branch_id', 'staff_profile_id', 'message_body_encrypted',
        'message_template_id', 'recipient_count', 'message_character_count', 'segment_count',
        'estimated_cost_minor', 'final_cost_minor', 'currency', 'status', 'failure_reason_code',
        'consent_snapshot_at', 'created_by', 'confirmed_at', 'queued_at', 'completed_at', 'cancelled_at',
    ] as $column) {
        expect(Schema::hasColumn('personnel_sms_campaigns', $column))->toBeTrue("campaigns.{$column}");
    }

    // NO contact column of any kind lives on the campaign (ADR-010).
    foreach (['phone', 'phone_encrypted', 'phone_last_four', 'email', 'client_id', 'msisdn'] as $forbidden) {
        expect(Schema::hasColumn('personnel_sms_campaigns', $forbidden))
            ->toBeFalse("campaigns must not carry {$forbidden}");
    }

    $checks = p21sChecks('personnel_sms_campaigns');
    foreach ([
        'personnel_sms_campaigns_status_check',
        'personnel_sms_campaigns_currency_check',
        'personnel_sms_campaigns_cost_nonneg_check',
        'personnel_sms_campaigns_template_check',
        'personnel_sms_campaigns_confirmed_at_check',
        'personnel_sms_campaigns_consent_snapshot_check',
        'personnel_sms_campaigns_recipient_count_check',
    ] as $check) {
        expect($checks)->toContain($check);
    }

    expect(p21sTriggers('personnel_sms_campaigns'))->toContain('personnel_sms_campaigns_guard_trigger');
    expect(p21sIndexes('personnel_sms_campaigns'))->toContain('personnel_sms_campaigns_id_merchant_id_unique');
});

it('gives personnel_sms_recipients a NULLABLE phone snapshot, the dedupe key and both triggers', function (): void {
    // Plan §74 data minimization: a suppressed recipient's number is never stored at all.
    $nullable = DB::table('information_schema.columns')
        ->where('table_name', 'personnel_sms_recipients')
        ->where('column_name', 'phone_encrypted')
        ->value('is_nullable');
    expect($nullable)->toBe('YES');

    $checks = p21sChecks('personnel_sms_recipients');
    foreach ([
        'personnel_sms_recipients_delivery_status_check',
        'personnel_sms_recipients_consent_snapshot_check',
        'personnel_sms_recipients_last_four_check',
        'personnel_sms_recipients_phone_check',
        'personnel_sms_recipients_phone_required_check',
        'personnel_sms_recipients_snapshot_no_phone_check',
        'personnel_sms_recipients_undispatched_check',
    ] as $check) {
        expect($checks)->toContain($check);
    }

    // The jsonb guard names every phone-ish key it forbids.
    $clause = p21sCheckClause('personnel_sms_recipients_snapshot_no_phone_check');
    foreach (['phone', 'phone_encrypted', 'msisdn', 'phone_number'] as $key) {
        expect($clause)->toContain($key);
    }

    expect(p21sTriggers('personnel_sms_recipients'))
        ->toContain('personnel_sms_recipients_guard_trigger')
        ->toContain('personnel_sms_recipients_no_delete_trigger');

    // The Plan §64 dedupe key + the tenant-scoped read index.
    $indexes = p21sIndexes('personnel_sms_recipients');
    expect($indexes)->toContain('personnel_sms_recipients_campaign_id_client_id_unique')
        ->toContain('personnel_sms_recipients_merchant_id_branch_id_index');
});

it('keeps sms_delivery_attempts append-only with a digit-run redaction CHECK', function (): void {
    $checks = p21sChecks('sms_delivery_attempts');
    foreach ([
        'sms_delivery_attempts_status_check',
        'sms_delivery_attempts_result_class_check',
        'sms_delivery_attempts_attempt_number_check',
        'sms_delivery_attempts_next_retry_check',
        'sms_delivery_attempts_redaction_check',
    ] as $check) {
        expect($checks)->toContain($check);
    }

    // The redaction backstop is a 7+ digit-run rejection, not a vague "looks fine".
    expect(p21sCheckClause('sms_delivery_attempts_redaction_check'))->toContain('[0-9]{7}');

    expect(p21sTriggers('sms_delivery_attempts'))->toContain('sms_delivery_attempts_append_only_trigger');
    expect(p21sIndexes('sms_delivery_attempts'))
        ->toContain('sms_delivery_attempts_recipient_id_attempt_number_unique');
});

it('keeps sms_billing_entries integer-only with one live entry per campaign', function (): void {
    $checks = p21sChecks('sms_billing_entries');
    foreach ([
        'sms_billing_entries_status_check',
        'sms_billing_entries_currency_check',
        'sms_billing_entries_amounts_check',
        'sms_billing_entries_amount_product_check',
        'sms_billing_entries_invoice_line_check',
    ] as $check) {
        expect($checks)->toContain($check);
    }

    // ADR-005: the amount IS the product; it is never independently supplied.
    expect(p21sCheckClause('sms_billing_entries_amount_product_check'))
        ->toContain('quantity')
        ->toContain('unit_cost_minor');

    expect(p21sIndexes('sms_billing_entries'))->toContain('sms_billing_entries_live_campaign_unique');

    // Money columns are integers, never numeric/float (ADR-005).
    foreach (['quantity', 'unit_cost_minor', 'amount_minor'] as $column) {
        $type = DB::table('information_schema.columns')
            ->where('table_name', 'sms_billing_entries')
            ->where('column_name', $column)
            ->value('data_type');
        expect($type)->toBeIn(['integer', 'bigint'], "sms_billing_entries.{$column} must be an integer type");
    }
});

it('makes every Phase 21S foreign key RESTRICT on delete', function (): void {
    $restrictions = [
        'personnel_sms_campaigns' => ['personnel_sms_campaigns_merchant_id_foreign', 'personnel_sms_campaigns_branch_id_foreign', 'personnel_sms_campaigns_staff_profile_id_foreign', 'personnel_sms_campaigns_created_by_foreign'],
        'personnel_sms_recipients' => ['personnel_sms_recipients_campaign_id_foreign', 'personnel_sms_recipients_client_id_foreign', 'personnel_sms_recipients_service_session_id_foreign'],
        'sms_delivery_attempts' => ['sms_delivery_attempts_recipient_id_foreign'],
        'sms_billing_entries' => ['sms_billing_entries_campaign_id_foreign', 'sms_billing_entries_billing_invoice_line_id_foreign'],
    ];

    foreach ($restrictions as $table => $constraints) {
        foreach ($constraints as $constraint) {
            expect(p21sFkDeleteRule($table, $constraint))->toBe('RESTRICT', "{$constraint} must RESTRICT");
        }
    }
});

it('carries the composite merchant-consistency foreign keys', function (): void {
    $composites = [
        'personnel_sms_campaigns' => ['personnel_sms_campaigns_branch_merchant_foreign', 'personnel_sms_campaigns_staff_merchant_foreign'],
        'personnel_sms_recipients' => [
            'personnel_sms_recipients_branch_merchant_foreign',
            'personnel_sms_recipients_campaign_merchant_foreign',
            'personnel_sms_recipients_client_merchant_foreign',
            'personnel_sms_recipients_session_merchant_foreign',
        ],
        'sms_billing_entries' => ['sms_billing_entries_branch_merchant_foreign', 'sms_billing_entries_campaign_merchant_foreign'],
    ];

    foreach ($composites as $table => $constraints) {
        /** @var list<string> $existing */
        $existing = DB::table('information_schema.table_constraints')
            ->where('table_name', $table)
            ->where('constraint_type', 'FOREIGN KEY')
            ->pluck('constraint_name')
            ->all();

        foreach ($constraints as $constraint) {
            expect($existing)->toContain($constraint);
        }
    }
});
