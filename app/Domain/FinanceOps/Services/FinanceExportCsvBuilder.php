<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Services;

use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\FinanceOps\Enums\FinanceExportType;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Refunds\Models\Refund;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Builds the MASKED, SCOPED CSV for a finance export (Plan §65, §67; Gate I; Phase
 * 18B). Runs inside the tenant context (merchant global scope active) and applies the
 * optional branch scope IN THE QUERY (never an unscoped export then client filtering).
 * Rows carry public references + integer minor-unit amounts only — NEVER a full/
 * normalized payment reference, a full client contact, or an internal id. Rows are
 * streamed in bounded chunks so a large export never loads the whole table into memory.
 */
final class FinanceExportCsvBuilder
{
    private const CHUNK = 500;

    /** @return array{0: string, 1: int} [csv, rowCount] */
    public function build(FinanceExport $export): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the export buffer.');
        }

        $rowCount = match ($export->export_type) {
            FinanceExportType::Invoices => $this->writeInvoices($export, $stream),
            FinanceExportType::Payments => $this->writePayments($export, $stream),
            FinanceExportType::Receipts => $this->writeReceipts($export, $stream),
            FinanceExportType::CashUp => $this->writeCashUps($export, $stream),
            FinanceExportType::Refunds => $this->writeRefunds($export, $stream),
            FinanceExportType::Disputes => $this->writeDisputes($export, $stream),
            // Unsupported types are rejected at request time, so the job never reaches them.
            default => 0,
        };

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return [$csv, $rowCount];
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  resource  $stream
     * @param  list<string>  $header
     * @param  callable(mixed): list<string|int>  $mapper
     */
    private function stream(Builder $query, FinanceExport $export, $stream, array $header, callable $mapper): int
    {
        if ($export->branch_id !== null) {
            $query->where('branch_id', $export->branch_id);
        }

        fputcsv($stream, $header);

        $rowCount = 0;
        $query->orderBy('id')->chunkById(self::CHUNK, function ($rows) use ($stream, $mapper, &$rowCount): void {
            foreach ($rows as $row) {
                fputcsv($stream, $mapper($row));
                $rowCount++;
            }
        });

        return $rowCount;
    }

    /** @param resource $stream */
    private function writeInvoices(FinanceExport $export, $stream): int
    {
        return $this->stream(Invoice::query(), $export, $stream,
            ['invoice_number', 'status', 'total_minor', 'currency', 'created_at'],
            static fn (Invoice $i): array => [(string) $i->invoice_number, $i->status->value, $i->total_minor, $i->currency, self::iso($i->created_at)],
        );
    }

    /** @param resource $stream */
    private function writePayments(FinanceExport $export, $stream): int
    {
        return $this->stream(PaymentRecord::query(), $export, $stream,
            ['id', 'method', 'amount_minor', 'currency', 'status', 'reference_masked', 'paid_at'],
            static fn (PaymentRecord $p): array => [$p->ulid, $p->method->value, $p->amount_minor, $p->currency, $p->status->value, (string) $p->maskedReference(), self::iso($p->paid_at)],
        );
    }

    /** @param resource $stream */
    private function writeReceipts(FinanceExport $export, $stream): int
    {
        return $this->stream(Receipt::query(), $export, $stream,
            ['receipt_number', 'amount_minor', 'currency', 'created_at'],
            static fn (Receipt $r): array => [$r->receipt_number, $r->amount_minor, $r->currency, self::iso($r->created_at)],
        );
    }

    /** @param resource $stream */
    private function writeCashUps(FinanceExport $export, $stream): int
    {
        return $this->stream(BranchCashUp::query()->whereNotNull('business_date'), $export, $stream,
            ['id', 'business_date', 'status', 'expected_minor', 'counted_minor', 'variance_minor'],
            static fn (BranchCashUp $c): array => [$c->ulid, self::date($c->business_date), $c->status->value, $c->expected_minor, $c->counted_minor, $c->variance_minor],
        );
    }

    /** @param resource $stream */
    private function writeRefunds(FinanceExport $export, $stream): int
    {
        return $this->stream(Refund::query(), $export, $stream,
            ['id', 'method', 'amount_minor', 'currency', 'status', 'reference_masked', 'created_at'],
            static fn (Refund $r): array => [$r->ulid, $r->method->value, $r->amount_minor, $r->currency, $r->status->value, (string) $r->maskedReference(), self::iso($r->created_at)],
        );
    }

    /** @param resource $stream */
    private function writeDisputes(FinanceExport $export, $stream): int
    {
        return $this->stream(FinanceDispute::query(), $export, $stream,
            ['id', 'status', 'created_at'],
            static fn (FinanceDispute $d): array => [$d->ulid, $d->status->value, self::iso($d->created_at)],
        );
    }

    private static function iso(?CarbonInterface $timestamp): string
    {
        return $timestamp?->toIso8601String() ?? '';
    }

    private static function date(?CarbonInterface $timestamp): string
    {
        return $timestamp?->toDateString() ?? '';
    }
}
