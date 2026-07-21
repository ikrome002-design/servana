<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\PersonnelPayoutRunFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PersonnelPayoutRun — an internal personnel payout run (Plan §62; Phase 20H; table
 * `personnel_payout_runs`). Branch-owned. HR drafts/submits; Finance verifies/approves/marks-paid;
 * Merchant Admin approves high-value. **Servana moves no money.** Money is integer minor units
 * (ADR-005); the external payment reference is encrypted at rest.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $currency
 * @property int|null $high_value_threshold_snapshot_minor
 * @property PayoutRunStatus $status
 * @property int $gross_total_minor
 * @property int $created_by
 * @property int|null $submitted_by
 * @property int|null $verified_by
 * @property int|null $approved_by
 * @property int|null $paid_by
 * @property int|null $rejected_by
 * @property string|null $rejection_reason
 * @property string|null $external_payment_reference_encrypted
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PersonnelPayoutRun extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PersonnelPayoutRunFactory> */
    use HasFactory;

    protected $table = 'personnel_payout_runs';

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'period_start',
        'period_end',
        'currency',
        'high_value_threshold_snapshot_minor',
        'status',
        'gross_total_minor',
        'created_by',
        'submitted_by',
        'verified_by',
        'approved_by',
        'paid_by',
        'rejected_by',
        'rejection_reason',
        'external_payment_reference_encrypted',
        'paid_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return Factory<PersonnelPayoutRun> */
    protected static function newFactory(): Factory
    {
        return PersonnelPayoutRunFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PersonnelPayoutRun $run): void {
            if (! isset($run->ulid)) {
                $run->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'high_value_threshold_snapshot_minor' => 'integer',
            'status' => PayoutRunStatus::class,
            'gross_total_minor' => 'integer',
            'external_payment_reference_encrypted' => 'encrypted',
            'paid_at' => 'datetime',
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

    /** @return HasMany<PersonnelPayoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PersonnelPayoutItem::class, 'payout_run_id');
    }
}
