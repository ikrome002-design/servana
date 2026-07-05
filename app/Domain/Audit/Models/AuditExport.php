<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Jobs\GenerateAuditExport;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\AuditExportFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * AuditExport — async, reason-gated, permission-masked, signed/expiring,
 * download-counted Audit export request (Plan §13.5, §19.2/§19.3, §80; Phase 19;
 * ADR-010). Branch-owned (`branch_id` NOT NULL); the ULID is the public id + route
 * key. Generated via {@see GenerateAuditExport} (reports-exports
 * queue) writing a private CSV through the Phase 10F file domain (`FilePurpose::AuditExport`).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $requested_by_user_id
 * @property string $reason
 * @property array<string, mixed> $scope_json
 * @property AuditExportStatus $status
 * @property int|null $file_id
 * @property int|null $row_count
 * @property int $download_count
 * @property Carbon|null $first_downloaded_at
 * @property Carbon|null $last_downloaded_at
 * @property Carbon|null $requested_at
 * @property Carbon|null $processing_started_at
 * @property Carbon|null $generated_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $expired_at
 * @property string|null $failure_code
 * @property string|null $failure_message_redacted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditExport extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<AuditExportFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'requested_by_user_id',
        'reason',
        'scope_json',
        'status',
        'file_id',
        'row_count',
        'download_count',
        'first_downloaded_at',
        'last_downloaded_at',
        'requested_at',
        'processing_started_at',
        'generated_at',
        'failed_at',
        'expires_at',
        'revoked_at',
        'expired_at',
        'failure_code',
        'failure_message_redacted',
    ];

    /** @return Factory<AuditExport> */
    protected static function newFactory(): Factory
    {
        return AuditExportFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (AuditExport $export): void {
            if (! isset($export->ulid)) {
                $export->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AuditExportStatus::class,
            'scope_json' => 'array',
            'row_count' => 'integer',
            'download_count' => 'integer',
            'first_downloaded_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
            'requested_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'generated_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function isDownloadable(): bool
    {
        return $this->status === AuditExportStatus::Ready && $this->file_id !== null;
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
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<UploadedFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'file_id');
    }
}
