<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\PayoutItemStatus;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\PersonnelPayoutItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PersonnelPayoutItem — a frozen snapshot line of a personnel payout run (Plan §62; Phase 20H;
 * table `personnel_payout_items`). Branch-owned. Snapshots eligible unpaid 20G ledger facts into
 * bucketed sums with the exact row identities in `source_ledger_refs`; single-currency; the status
 * always mirrors the parent run. Immutable once the run leaves draft (DB-enforced). Money is integer
 * minor units (ADR-005).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $payout_run_id
 * @property int $staff_profile_id
 * @property string $currency
 * @property int $salary_amount_minor
 * @property int $commission_amount_minor
 * @property int $adjustment_amount_minor
 * @property int $gross_amount_minor
 * @property array<string, list<int>> $source_ledger_refs
 * @property PayoutItemStatus $status
 * @property int|null $earnings_statement_file_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PersonnelPayoutItem extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PersonnelPayoutItemFactory> */
    use HasFactory;

    protected $table = 'personnel_payout_items';

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'payout_run_id',
        'staff_profile_id',
        'currency',
        'salary_amount_minor',
        'commission_amount_minor',
        'adjustment_amount_minor',
        'gross_amount_minor',
        'source_ledger_refs',
        'status',
        'earnings_statement_file_id',
    ];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return Factory<PersonnelPayoutItem> */
    protected static function newFactory(): Factory
    {
        return PersonnelPayoutItemFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PersonnelPayoutItem $item): void {
            if (! isset($item->ulid)) {
                $item->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'salary_amount_minor' => 'integer',
            'commission_amount_minor' => 'integer',
            'adjustment_amount_minor' => 'integer',
            'gross_amount_minor' => 'integer',
            'source_ledger_refs' => 'array',
            'status' => PayoutItemStatus::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    /** @return BelongsTo<PersonnelPayoutRun, $this> */
    public function payoutRun(): BelongsTo
    {
        return $this->belongsTo(PersonnelPayoutRun::class, 'payout_run_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /** @return BelongsTo<UploadedFile, $this> */
    public function earningsStatementFile(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'earnings_statement_file_id');
    }
}
