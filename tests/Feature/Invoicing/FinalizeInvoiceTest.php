<?php

declare(strict_types=1);

use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Invoicing\Actions\CreateInvoiceDraft;
use App\Domain\Invoicing\Actions\FinalizeInvoice;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoiceSourceException;
use App\Domain\Invoicing\Exceptions\InvoiceStateException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('invoicing', 'invoice-finalize');

// invoiceScenario() + completedSessionFor() live in tests/Pest.php (shared, parallel-safe).

it('finalizes a draft: allocates a number, snapshots totals, transitions draft → issued', function (): void {
    $scn = invoiceScenario();
    $session = completedSessionFor($scn);

    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [$session], $scn['actor']);

    // Draft has no number and no finalized_at (number only at finalization).
    expect($draft->status)->toBe(InvoiceStatus::Draft)
        ->and($draft->invoice_number)->toBeNull()
        ->and($draft->finalized_at)->toBeNull()
        ->and($draft->total_minor)->toBe(500000);

    $issued = app(FinalizeInvoice::class)->handle($draft, $scn['actor']);

    expect($issued->status)->toBe(InvoiceStatus::Issued)
        ->and($issued->invoice_number)->toBe('KIL-INV-000001')
        ->and($issued->finalized_at)->not->toBeNull()
        ->and($issued->subtotal_minor)->toBe(500000)
        ->and($issued->total_minor)->toBe(500000)
        ->and($issued->preferred_personnel_fee_snapshot_minor)->toBeNull()
        ->and($issued->percentage_fee_config_snapshot)->toBeNull();
});

it('does not recalculate an issued invoice when the service price later changes', function (): void {
    $scn = invoiceScenario(500000);
    $session = completedSessionFor($scn);
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [$session], $scn['actor']);
    $issued = app(FinalizeInvoice::class)->handle($draft, $scn['actor']);

    // Change the service price AFTER finalization — the snapshot must be immutable.
    $scn['service']->forceFill(['price_minor' => 999999])->save();

    $reloaded = Invoice::query()->whereKey($issued->id)->firstOrFail();
    expect($reloaded->total_minor)->toBe(500000)
        ->and($reloaded->items()->first()->unit_price_minor)->toBe(500000);
});

it('snapshots the effective preferred-personnel fee RULE only when the session honoured the request (Phase 20A seam)', function (): void {
    // Phase 20A replaces the legacy `services.preferred_personnel_fee_minor` seam with the
    // rule-backed resolver: finalization now resolves the effective preferred-fee rule. An
    // active service-scoped fixed rule of 20000 applies to the honoured session.
    $scn = invoiceScenario(500000);
    PreferredPersonnelFeeRule::factory()->service($scn['service'])->create([
        'fixed_amount_minor' => 20000,
        'currency' => 'KES',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'status' => 'active',
    ]);
    $session = completedSessionFor($scn, preferredHonored: true);
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [$session], $scn['actor']);
    $issued = app(FinalizeInvoice::class)->handle($draft, $scn['actor']);

    expect($issued->preferred_personnel_fee_snapshot_minor)->toBe(20000)
        ->and($issued->total_minor)->toBe(520000) // 500000 + 20000
        ->and($issued->items()->first()->preferred_personnel_fee_minor)->toBe(20000);
});

it('charges no preferred fee when the request was not honoured or not made', function (): void {
    $scn = invoiceScenario(500000, preferredFeeMinor: 20000);
    $notHonoured = completedSessionFor($scn, preferredHonored: false);
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [$notHonoured], $scn['actor']);
    $issued = app(FinalizeInvoice::class)->handle($draft, $scn['actor']);

    expect($issued->preferred_personnel_fee_snapshot_minor)->toBeNull()
        ->and($issued->total_minor)->toBe(500000);
});

it('allocates gap-free sequential numbers across finalizations', function (): void {
    $scn = invoiceScenario();
    $first = app(FinalizeInvoice::class)->handle(
        app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']),
        $scn['actor'],
    );
    $second = app(FinalizeInvoice::class)->handle(
        app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']),
        $scn['actor'],
    );

    expect($first->invoice_number)->toBe('KIL-INV-000001')
        ->and($second->invoice_number)->toBe('KIL-INV-000002');
});

it('rejects drafting from a non-completed service session', function (): void {
    $scn = invoiceScenario();
    $pending = ServiceSession::factory()->create([
        'merchant_id' => $scn['merchantId'],
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'service_id' => $scn['service']->id,
        'staff_profile_id' => $scn['staff']->id,
        'queue_entry_id' => null,
        'status' => 'pending',
    ]);

    expect(fn () => app(CreateInvoiceDraft::class)->handle($scn['client'], [$pending], $scn['actor']))
        ->toThrow(InvoiceSourceException::class);
});

it('prevents invoicing the same completed session twice', function (): void {
    $scn = invoiceScenario();
    $session = completedSessionFor($scn);
    app(FinalizeInvoice::class)->handle(
        app(CreateInvoiceDraft::class)->handle($scn['client'], [$session], $scn['actor']),
        $scn['actor'],
    );

    expect(fn () => app(CreateInvoiceDraft::class)->handle($scn['client'], [$session], $scn['actor']))
        ->toThrow(InvoiceSourceException::class);
});

it('cannot finalize an already-issued invoice (no double finalization)', function (): void {
    $scn = invoiceScenario();
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']);
    $issued = app(FinalizeInvoice::class)->handle($draft, $scn['actor']);

    expect(fn () => app(FinalizeInvoice::class)->handle($issued, $scn['actor']))
        ->toThrow(InvoiceStateException::class);
});

it('rolls back numbering when finalization fails (no number consumed)', function (): void {
    $scn = invoiceScenario();
    $session = completedSessionFor($scn);
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [$session], $scn['actor']);

    // Make the source non-completed AFTER drafting so finalization aborts mid-transaction
    // (clear completed_at to satisfy the status↔timestamp coherence CHECK).
    $session->forceFill([
        'status' => 'cancelled',
        'completed_at' => null,
        'cancelled_at' => now(),
        'cancellation_reason' => 'test',
    ])->save();

    expect(fn () => app(FinalizeInvoice::class)->handle($draft, $scn['actor']))
        ->toThrow(InvoiceSourceException::class);

    // No number was consumed by the rolled-back attempt.
    $next = app(CreateInvoiceDraft::class)->handle(
        $scn['client'],
        [completedSessionFor($scn)],
        $scn['actor'],
    );
    $issued = app(FinalizeInvoice::class)->handle($next, $scn['actor']);
    expect($issued->invoice_number)->toBe('KIL-INV-000001');
});
