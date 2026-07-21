<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\PayoutItemStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Services\EarningsStatementDocumentRenderer;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Files\Services\GeneratedFileWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Generates (or returns the existing) earnings-statement PDF for a PAID payout item (Plan §63, §65;
 * §H11; D-H11). On-demand + IDEMPOTENT: the statement is written once through the 10F
 * {@see GeneratedFileWriter} (purpose `earnings_statement`, `owner_user_id` = the personnel user, so
 * download is own-scope-authorised by FileAccessService) and linked via
 * `personnel_payout_items.earnings_statement_file_id`. A subsequent call returns the same file — the
 * statement is IMMUTABLE (a later correction is a new adjustment + a future statement, never a
 * rewrite). Only a `paid` item may be stated. Audits `earnings_statement.generated` (info).
 */
final class GenerateEarningsStatement
{
    public function __construct(
        private readonly EarningsStatementDocumentRenderer $renderer,
        private readonly GeneratedFileWriter $writer,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelPayoutItem $item): UploadedFile
    {
        return DB::transaction(function () use ($item): UploadedFile {
            $locked = PersonnelPayoutItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->earnings_statement_file_id !== null) {
                /** @var UploadedFile $existing */
                $existing = UploadedFile::query()->findOrFail($locked->earnings_statement_file_id);

                return $existing;
            }

            if ($locked->status !== PayoutItemStatus::Paid) {
                throw CompensationStateException::invalidTransition('personnel payout item', $locked->status->value, 'earnings_statement');
            }

            $ownerUserId = $locked->staffProfile?->merchantUser?->user_id;

            $file = $this->writer->write(
                FilePurpose::EarningsStatement,
                $this->renderer->render($locked),
                'earnings-statement-'.$locked->ulid.'.pdf',
                'application/pdf',
                'pdf',
                $locked->merchant_id,
                $locked->branch_id,
                $ownerUserId,
                $ownerUserId,
            );

            $locked->earnings_statement_file_id = $file->id;
            $locked->save();

            $this->audit->record(
                AuditEvent::EarningsStatementGenerated,
                null,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'payout_item_id' => $locked->ulid,
                    'payout_run_id' => $locked->payoutRun?->ulid,
                    'statement_file_id' => $file->ulid,
                    'currency' => $locked->currency,
                ],
            );

            return $file;
        });
    }
}
