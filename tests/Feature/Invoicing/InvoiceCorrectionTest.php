<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Invoicing\Actions\AdjustInvoice;
use App\Domain\Invoicing\Actions\CreateInvoiceDraft;
use App\Domain\Invoicing\Actions\ExecuteInvoiceVoid;
use App\Domain\Invoicing\Actions\FinalizeInvoice;
use App\Domain\Invoicing\Actions\RejectInvoiceVoid;
use App\Domain\Invoicing\Actions\RequestInvoiceVoid;
use App\Domain\Invoicing\Actions\UpdateInvoiceDraft;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoiceStateException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('invoicing', 'invoice-correction');

it('recalculates a draft when its source set is replaced', function (): void {
    $scn = invoiceScenario(500000);
    $first = completedSessionFor($scn);
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [$first], $scn['actor']);
    expect($draft->total_minor)->toBe(500000);

    // Replace with two completed sessions → total recomputed; first source freed.
    $a = completedSessionFor($scn);
    $b = completedSessionFor($scn);
    $updated = app(UpdateInvoiceDraft::class)->handle($draft, $scn['client'], [$a, $b], $scn['actor']);

    expect($updated->total_minor)->toBe(1000000)
        ->and($updated->items()->count())->toBe(2)
        ->and($updated->invoice_number)->toBeNull();
});

it('cannot update an issued invoice as a draft', function (): void {
    $scn = invoiceScenario();
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']);
    $issued = app(FinalizeInvoice::class)->handle($draft, $scn['actor']);

    expect(fn () => app(UpdateInvoiceDraft::class)->handle($issued, $scn['client'], [completedSessionFor($scn)], $scn['actor']))
        ->toThrow(InvoiceStateException::class);
});

it('rejects a void request and restores the prior payable state', function (): void {
    $scn = invoiceScenario();
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']);
    $issued = app(FinalizeInvoice::class)->handle($draft, $scn['actor']);

    $pending = app(RequestInvoiceVoid::class)->handle($issued, $scn['actor'], 'Maybe a duplicate.');
    expect($pending->status)->toBe(InvoiceStatus::VoidPending)
        ->and($pending->previous_status)->toBe(InvoiceStatus::Issued);

    $restored = app(RejectInvoiceVoid::class)->handle($pending, $scn['actor']);
    expect($restored->status)->toBe(InvoiceStatus::Issued)
        ->and($restored->previous_status)->toBeNull()
        ->and($restored->void_reason)->toBeNull();
});

it('adjusts an issued invoice additively without mutating its snapshots or number', function (): void {
    $scn = invoiceScenario(500000);
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']);
    $issued = app(FinalizeInvoice::class)->handle($draft, $scn['actor']);
    $number = $issued->invoice_number;

    $adjusted = app(AdjustInvoice::class)->handle($issued, $scn['actor'], 'Corrected a mischarged service.');

    expect($adjusted->status)->toBe(InvoiceStatus::Adjusted)
        ->and($adjusted->invoice_number)->toBe($number)        // number retained
        ->and($adjusted->total_minor)->toBe(500000)            // snapshot unchanged
        ->and($adjusted->adjustment_reason)->toBe('Corrected a mischarged service.')
        ->and($adjusted->adjusted_by)->toBe($scn['actor']->id)
        ->and($adjusted->items()->count())->toBe(1);           // nothing deleted
});

it('writes one coherent, safe audit event per committed invoice mutation', function (): void {
    $scn = invoiceScenario();
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']);
    app(FinalizeInvoice::class)->handle($draft, $scn['actor']);

    $created = AuditLog::query()->where('action', 'invoice.created')->latest('id')->firstOrFail();
    $finalized = AuditLog::query()->where('action', 'invoice.finalized')->latest('id')->firstOrFail();

    expect($created->merchant_id)->toBe($scn['merchantId'])
        ->and($created->branch_id)->toBe($scn['branch']->id)
        ->and($created->actor_id)->toBe($scn['actor']->id)
        ->and($created->severity)->toBe(AuditSeverity::Info)
        ->and($finalized->context['invoice_number'])->toBe('KIL-INV-000001')
        ->and($finalized->context['new_state'])->toBe('issued')
        // Safe context only — ULIDs + money, no full contact / blind index / raw key.
        ->and($finalized->context)->not->toHaveKey('phone')
        ->and($finalized->context)->not->toHaveKey('phone_encrypted')
        ->and($finalized->context)->not->toHaveKey('phone_blind_index')
        ->and($finalized->context['invoice_id'])->toBe($draft->ulid);
});

it('audits the Finance void with high severity and the adjust with high severity', function (): void {
    $scn = invoiceScenario();
    $draft = app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn)], $scn['actor']);
    $issued = app(FinalizeInvoice::class)->handle($draft, $scn['actor']);

    $pending = app(RequestInvoiceVoid::class)->handle($issued, $scn['actor'], 'Duplicate.');
    app(ExecuteInvoiceVoid::class)->handle($pending, $scn['actor']);

    expect(AuditLog::query()->where('action', 'invoice.void_requested')->value('severity'))->toBe(AuditSeverity::High)
        ->and(AuditLog::query()->where('action', 'invoice.voided')->value('severity'))->toBe(AuditSeverity::High);
});
