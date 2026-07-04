<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use App\Domain\FinanceOps\Enums\FinanceExportType;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\FinanceExportFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FinanceExport — async, scoped, masked finance export request (Plan §65, §67; Gate I;
 * Phase 18B). Merchant-owned with an optional branch scope. ULID is the public id +
 * route key. Generated via GenerateFinanceExport (reports-exports queue) writing a
 * private CSV through the Phase 10F file domain (`finance_export`).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int|null $branch_id
 * @property int $requested_by
 * @property FinanceExportType $export_type
 * @property array<string, mixed> $scope_json
 * @property string $reason
 * @property FinanceExportStatus $status
 * @property int|null $file_id
 * @property int|null $row_count
 * @property int $download_count
 * @property string|null $failure_code
 * @property string|null $failure_message_redacted
 * @property Carbon|null $expires_at
 * @property Carbon|null $first_downloaded_at
 * @property Carbon|null $last_downloaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinanceExport extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<FinanceExportFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'requested_by',
        'export_type',
        'scope_json',
        'reason',
        'status',
        'file_id',
        'row_count',
        'expires_at',
        'first_downloaded_at',
        'last_downloaded_at',
        'download_count',
        'failure_code',
        'failure_message_redacted',
    ];

    /** @return Factory<FinanceExport> */
    protected static function newFactory(): Factory
    {
        return FinanceExportFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (FinanceExport $export): void {
            if (! isset($export->ulid)) {
                $export->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'export_type' => FinanceExportType::class,
            'status' => FinanceExportStatus::class,
            'scope_json' => 'array',
            'row_count' => 'integer',
            'download_count' => 'integer',
            'expires_at' => 'datetime',
            'first_downloaded_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
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

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<UploadedFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'file_id');
    }
}
