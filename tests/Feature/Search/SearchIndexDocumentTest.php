<?php

declare(strict_types=1);

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Search\Contracts\SearchDocumentDefinition;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Services\SearchDocumentCatalogue;
use App\Domain\Search\Support\SearchIndexName;

uses()->group('search', 'phase22', 'security');

/*
 |==============================================================================
 | Index documents are an EXPLICIT ALLOWLIST (search-catalogue.md §2, §5.2).
 |
 | These tests build models in memory — no database, no engine — and assert the
 | emitted key set is EXACTLY the catalogue's declared set. An extra key fails,
 | which is what makes "adding a column can never silently index it" a guarantee
 | rather than a convention.
 |==============================================================================
 */

/** Every field name that must never appear in any index document. */
const P22_FORBIDDEN_DOCUMENT_KEYS = [
    'phone', 'phone_encrypted', 'phone_index', 'phone_last_four',
    'email', 'email_encrypted', 'email_masked', 'phone_masked',
    'notes', 'cancellation_reason', 'transfer_reason', 'void_reason',
    'adjustment_reason', 'preferred_personnel_override_reason',
    'estimated_wait_override_reason', 'percentage_fee_config_snapshot',
    'components', 'message_body', 'destination', 'profile_photo_path',
];

it('emits exactly the declared keys for a client document', function (): void {
    $client = new Client(['merchant_id' => 7, 'branch_id' => 3, 'full_name' => 'Amina Wanjiku']);
    $client->ulid = 'CLIENTULID';

    $document = definitionFor(SearchDocumentType::Client)->indexDocumentFor($client);

    expect(array_keys($document))->toBe(['id', 'merchant_id', 'branch_id', 'full_name'])
        ->and($document['id'])->toBe('CLIENTULID')
        ->and($document['merchant_id'])->toBe(7)
        ->and($document['branch_id'])->toBe(3)
        ->and($document['full_name'])->toBe('Amina Wanjiku');
});

it('emits exactly the declared keys for a staff document, and no phone', function (): void {
    $staff = new StaffProfile([
        'merchant_id' => 7,
        'primary_branch_id' => 3,
        'first_name' => 'Njeri',
        'last_name' => 'Kamau',
        'display_name' => 'Njeri Kamau',
        'role_title' => 'Senior stylist',
        // A PLAINTEXT column that StaffProfileResource does return — search must not.
        'phone' => '+254712345678',
    ]);
    $staff->ulid = 'STAFFULID';

    $document = definitionFor(SearchDocumentType::Staff)->indexDocumentFor($staff);

    expect(array_keys($document))->toBe([
        'id', 'merchant_id', 'branch_id', 'display_name', 'first_name', 'last_name', 'role_title',
    ])
        ->and($document['branch_id'])->toBe(3)
        ->and(json_encode($document))->not->toContain('712345678');
});

it('emits exactly the declared keys for the three scheduling documents', function (string $class, SearchDocumentType $type): void {
    $client = new Client(['full_name' => 'Amina Wanjiku']);
    $service = new Service(['name' => 'Signature Braiding']);

    /** @var Appointment|QueueEntry|ServiceSession $model */
    $model = new $class([
        'merchant_id' => 7,
        'branch_id' => 3,
        // Operator free text that must never be indexed.
        'cancellation_reason' => 'Client did not attend.',
        'notes' => 'Sensitive operator note about the client.',
    ]);
    $model->ulid = 'SCHEDULEULID';
    $model->setRelation('client', $client);
    $model->setRelation('service', $service);

    $document = definitionFor($type)->indexDocumentFor($model);

    expect(array_keys($document))->toBe([
        'id', 'merchant_id', 'branch_id', 'reference', 'client_name', 'service_name',
    ])
        ->and($document['reference'])->toBe('SCHEDULEULID')
        ->and($document['client_name'])->toBe('Amina Wanjiku')
        ->and($document['service_name'])->toBe('Signature Braiding');
})->with([
    'appointment' => [Appointment::class, SearchDocumentType::Appointment],
    'queue entry' => [QueueEntry::class, SearchDocumentType::QueueEntry],
    'service session' => [ServiceSession::class, SearchDocumentType::ServiceSession],
]);

it('emits exactly the declared keys for an invoice document', function (): void {
    $invoice = new Invoice([
        'merchant_id' => 7,
        'branch_id' => 3,
        'invoice_number' => 'INV-000123',
        'void_reason' => 'Duplicate.',
        'percentage_fee_config_snapshot' => ['rate_bp' => 250],
    ]);
    $invoice->ulid = 'INVOICEULID';
    $invoice->setRelation('client', new Client(['full_name' => 'Amina Wanjiku']));

    $document = definitionFor(SearchDocumentType::Invoice)->indexDocumentFor($invoice);

    expect(array_keys($document))->toBe([
        'id', 'merchant_id', 'branch_id', 'invoice_number', 'reference', 'client_name',
    ])
        ->and($document['invoice_number'])->toBe('INV-000123');
});

it('emits exactly the declared keys for a receipt document, and NO client name', function (): void {
    $receipt = new Receipt([
        'merchant_id' => 7,
        'branch_id' => 3,
        'receipt_number' => 4521,
        'components' => [['method' => 'cash', 'amount_minor' => 500000]],
    ]);
    $receipt->ulid = 'RECEIPTULID';
    $receipt->setRelation('invoice', new Invoice(['invoice_number' => 'INV-000123']));

    $document = definitionFor(SearchDocumentType::Receipt)->indexDocumentFor($receipt);

    // ReceiptResource exposes no client, so catalogue Rule 2 forbids search exposing one either.
    expect(array_keys($document))->toBe([
        'id', 'merchant_id', 'branch_id', 'receipt_number', 'invoice_number', 'reference',
    ])
        ->and($document)->not->toHaveKey('client_name')
        ->and($document['receipt_number'])->toBe('4521');
});

it('never emits a forbidden key in any index document', function (): void {
    $documents = [
        indexDocumentSample(SearchDocumentType::Client),
        indexDocumentSample(SearchDocumentType::Staff),
        indexDocumentSample(SearchDocumentType::Appointment),
        indexDocumentSample(SearchDocumentType::QueueEntry),
        indexDocumentSample(SearchDocumentType::ServiceSession),
        indexDocumentSample(SearchDocumentType::Invoice),
        indexDocumentSample(SearchDocumentType::Receipt),
    ];

    foreach ($documents as $document) {
        foreach (P22_FORBIDDEN_DOCUMENT_KEYS as $forbidden) {
            expect($document)->not->toHaveKey($forbidden);
        }
    }
});

it('carries the tenancy pair on every indexed document, because the query filter depends on it', function (): void {
    foreach (app(SearchDocumentCatalogue::class)->indexed() as $definition) {
        $document = indexDocumentSample($definition->type());

        expect($document)->toHaveKey('merchant_id')
            ->and($document)->toHaveKey('branch_id')
            ->and($document)->toHaveKey('id');
    }
});

it('refuses to build an index document for the non-indexed served_client type', function (): void {
    expect(fn () => definitionFor(SearchDocumentType::ServedClient)->indexDocumentFor(new Client))
        ->toThrow(RuntimeException::class);
});

it('refuses to build a document from the wrong model', function (): void {
    expect(fn () => definitionFor(SearchDocumentType::Client)->indexDocumentFor(new Invoice))
        ->toThrow(RuntimeException::class);
});

/*
 |--------------------------------------------------------------------------
 | Integration payload tables are never indexed (Plan §80 Phase 22; ADR-013)
 |--------------------------------------------------------------------------
 */

it('indexes no integration, provider or messaging payload table', function (): void {
    $catalogue = app(SearchDocumentCatalogue::class);

    $indexedTables = array_map(
        static fn (string $modelClass): string => (new $modelClass)->getTable(),
        $catalogue->indexedModelClasses(),
    );

    $forbiddenTables = [
        'referral_snapshots', 're_outbound_events', 're_event_deliveries',
        'personnel_sms_campaigns', 'personnel_sms_recipients', 'sms_delivery_attempts',
        'sms_billing_entries', 'audit_logs', 'audit_flagged_events', 'audit_exports',
        'idempotency_keys', 'magic_login_tokens', 'mfa_credentials', 'mfa_recovery_codes',
        'payment_records', 'payment_validation_events', 'payment_reference_checks',
        'client_consents', 'uploaded_files', 'file_scan_events',
    ];

    foreach ($forbiddenTables as $table) {
        expect($indexedTables)->not->toContain($table);
    }

    expect($indexedTables)->toEqualCanonicalizing([
        'clients', 'staff_profiles', 'appointments', 'queue_entries',
        'service_sessions', 'invoices', 'receipts',
    ]);
});

it('prefixes every index name per environment so environments cannot share an index', function (): void {
    foreach (app(SearchDocumentCatalogue::class)->indexed() as $definition) {
        $indexName = $definition->indexName();

        expect($indexName)->not->toBeNull()
            ->and(SearchIndexName::prefixed((string) $indexName))->toStartWith('servana_testing_');
    }
});

/*
 |--------------------------------------------------------------------------
 | Helpers
 |--------------------------------------------------------------------------
 */

function definitionFor(SearchDocumentType $type): SearchDocumentDefinition
{
    $definition = app(SearchDocumentCatalogue::class)->for($type);

    expect($definition)->not->toBeNull();

    return $definition;
}

/** @return array<string, mixed> */
function indexDocumentSample(SearchDocumentType $type): array
{
    $client = new Client(['full_name' => 'Amina Wanjiku', 'phone_last_four' => '5678']);
    $service = new Service(['name' => 'Signature Braiding']);
    $invoice = new Invoice(['invoice_number' => 'INV-000123']);

    $model = match ($type) {
        SearchDocumentType::Client => new Client(['merchant_id' => 7, 'branch_id' => 3, 'full_name' => 'Amina Wanjiku']),
        SearchDocumentType::Staff => new StaffProfile(['merchant_id' => 7, 'primary_branch_id' => 3, 'display_name' => 'Njeri Kamau', 'phone' => '+254712345678']),
        SearchDocumentType::Appointment => new Appointment(['merchant_id' => 7, 'branch_id' => 3]),
        SearchDocumentType::QueueEntry => new QueueEntry(['merchant_id' => 7, 'branch_id' => 3]),
        SearchDocumentType::ServiceSession => new ServiceSession(['merchant_id' => 7, 'branch_id' => 3]),
        SearchDocumentType::Invoice => new Invoice(['merchant_id' => 7, 'branch_id' => 3, 'invoice_number' => 'INV-000123']),
        SearchDocumentType::Receipt => new Receipt(['merchant_id' => 7, 'branch_id' => 3, 'receipt_number' => 4521]),
        SearchDocumentType::ServedClient => new Client,
    };

    $model->ulid = 'SAMPLEULID';

    if ($type !== SearchDocumentType::Client && $type !== SearchDocumentType::Staff) {
        $model->setRelation('client', $client);
        $model->setRelation('service', $service);
        $model->setRelation('invoice', $invoice);
    }

    return definitionFor($type)->indexDocumentFor($model);
}
