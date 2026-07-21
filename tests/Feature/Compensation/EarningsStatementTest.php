<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Actions\ApprovePayoutRunStandard;
use App\Domain\Compensation\Actions\GenerateEarningsStatement;
use App\Domain\Compensation\Actions\MarkPayoutRunPaid;
use App\Domain\Compensation\Actions\RecordCompensationAdjustment;
use App\Domain\Compensation\Actions\SubmitPayoutRun;
use App\Domain\Compensation\Actions\VerifyPayoutRun;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Models\UploadedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20h', 'phase20h-earnings');

/*
 | Phase 20H earnings statements (Plan §63, §65; §H11). On-demand + idempotent + immutable; written to
 | the 10F private file domain with owner_user_id = the personnel user (own-scope download authority).
 */

/** Drive a paid payout for one staff and return the paid item. */
function paidPayoutItem(MerchantBranch $branch): PersonnelPayoutItem
{
    $run = draftRun($branch);
    app(SubmitPayoutRun::class)->handle($run, User::factory()->create());
    app(VerifyPayoutRun::class)->handle($run->refresh(), User::factory()->create());
    app(ApprovePayoutRunStandard::class)->handle($run->refresh(), User::factory()->create());
    app(MarkPayoutRunPaid::class)->handle($run->refresh(), User::factory()->create(), 'REF-1', '2026-08-01');

    return $run->items()->firstOrFail();
}

it('generates a statement for a paid payout item owned by the personnel user', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    $item = paidPayoutItem($branch);

    $file = app(GenerateEarningsStatement::class)->handle($item);

    expect($file)->toBeInstanceOf(UploadedFile::class)
        ->and($file->purpose)->toBe(FilePurpose::EarningsStatement)
        ->and($file->owner_user_id)->toBe($staff->merchantUser->user_id)
        ->and($file->size_bytes)->toBeGreaterThan(0);
    expect($item->refresh()->earnings_statement_file_id)->toBe($file->id);
});

it('is idempotent — a second call returns the same statement, no new file', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    $item = paidPayoutItem($branch);

    $first = app(GenerateEarningsStatement::class)->handle($item);
    $second = app(GenerateEarningsStatement::class)->handle($item->refresh());

    expect($second->id)->toBe($first->id);
    expect(UploadedFile::query()->where('purpose', 'earnings_statement')->count())->toBe(1);
});

it('is immutable — a later adjustment does not rewrite the existing statement', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    $item = paidPayoutItem($branch);
    $original = app(GenerateEarningsStatement::class)->handle($item);

    // A later monetary correction is a NEW adjustment — the existing statement file is untouched.
    app(RecordCompensationAdjustment::class)->manual(
        $staff, $branch->id, -1000, 'KES', 'Correction after statement.', null, User::factory()->create(),
    );

    $again = app(GenerateEarningsStatement::class)->handle($item->refresh());
    expect($again->id)->toBe($original->id)
        ->and($again->sha256)->toBe($original->sha256);
});

it('refuses to generate a statement for a non-paid payout item', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    $run = draftRun($branch);
    app(SubmitPayoutRun::class)->handle($run, User::factory()->create()); // submitted, not paid

    expect(fn () => app(GenerateEarningsStatement::class)->handle($run->items()->firstOrFail()))
        ->toThrow(CompensationStateException::class);
});
