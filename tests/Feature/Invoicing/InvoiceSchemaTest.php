<?php

declare(strict_types=1);

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('invoicing', 'invoice-schema');

/** A finalized (issued) invoice with one item, via the factory. */
function schemaInvoice(): Invoice
{
    $invoice = Invoice::factory()->issued(500000)->create([
        'subtotal_minor' => 500000,
        'total_minor' => 500000,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'merchant_id' => $invoice->merchant_id,
        'branch_id' => $invoice->branch_id,
        'unit_price_minor' => 500000,
        'line_total_minor' => 500000,
    ]);

    return $invoice;
}

it('rejects an unsupported invoice status via the DB CHECK', function (): void {
    $invoice = schemaInvoice();
    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'bogus']))
        ->toThrow(QueryException::class);
});

it('rejects a non-uppercase / non-ISO currency via the DB CHECK', function (): void {
    $invoice = schemaInvoice();
    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['currency' => 'kes']))
        ->toThrow(QueryException::class);
});

it('enforces total arithmetic coherence (total = subtotal + preferred + tax - discount)', function (): void {
    $invoice = schemaInvoice();
    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total_minor' => 123]))
        ->toThrow(QueryException::class);
});

it('rejects validated_paid greater than total via the DB CHECK', function (): void {
    $invoice = schemaInvoice();
    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['validated_paid_minor' => 500001]))
        ->toThrow(QueryException::class);
});

it('rejects a negative money field via the DB CHECK', function (): void {
    $invoice = schemaInvoice();
    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['discount_minor' => -1]))
        ->toThrow(QueryException::class);
});

it('forbids a draft from carrying an invoice number (draft coherence CHECK)', function (): void {
    $draft = Invoice::factory()->create(); // draft: no number, no finalized_at
    expect(fn () => DB::table('invoices')->where('id', $draft->id)->update(['invoice_number' => 'X-INV-1']))
        ->toThrow(QueryException::class);
});

it('requires an issued invoice to carry a number + finalized_at (finalized coherence CHECK)', function (): void {
    $invoice = schemaInvoice();
    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['invoice_number' => null]))
        ->toThrow(QueryException::class);
});

it('enforces invoice-item line-total arithmetic (line_total = unit_price * quantity)', function (): void {
    $invoice = schemaInvoice();
    $itemId = InvoiceItem::query()->where('invoice_id', $invoice->id)->value('id');
    expect(fn () => DB::table('invoice_items')->where('id', $itemId)->update(['line_total_minor' => 7]))
        ->toThrow(QueryException::class);
});

it('prevents two invoice items from referencing the same completed service session', function (): void {
    $invoice = schemaInvoice();
    $existing = InvoiceItem::query()->where('invoice_id', $invoice->id)->firstOrFail();

    expect(fn () => InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'merchant_id' => $invoice->merchant_id,
        'branch_id' => $invoice->branch_id,
        'service_session_id' => $existing->service_session_id,
    ]))->toThrow(QueryException::class);
});

it('exposes the ulid as the route key and never the bigint id', function (): void {
    $invoice = schemaInvoice();
    expect($invoice->getRouteKeyName())->toBe('ulid')
        ->and($invoice->ulid)->toHaveLength(26);
});
