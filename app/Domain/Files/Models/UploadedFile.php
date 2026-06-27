<?php

declare(strict_types=1);

namespace App\Domain\Files\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Database\Factories\UploadedFileFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Private business file (Plan §13.13, §65; Phase 10F).
 *
 * Cross-cutting, nullable-scope: NOT a BelongsToMerchant model (platform-generated
 * files may have no merchant). Tenant/branch/owner isolation is enforced by
 * FileAccessService (every read re-checks scope) — never by a global scope that
 * would hide platform files. Status columns are NOT mass-assignable and change
 * ONLY through the transition methods below (called from file-domain actions/jobs).
 *
 * Storage disk/paths and the SHA-256 are `$hidden` so they never serialize.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $merchant_id
 * @property int|null $branch_id
 * @property int|null $owner_user_id
 * @property FilePurpose $purpose
 * @property string $storage_disk
 * @property string $quarantine_path
 * @property string|null $final_path
 * @property string $original_filename_encrypted
 * @property string $safe_download_filename
 * @property string|null $declared_mime_type
 * @property string|null $detected_mime_type
 * @property string|null $extension
 * @property int $size_bytes
 * @property string $sha256
 * @property FileScanStatus $scan_status
 * @property FileLifecycleStatus $lifecycle_status
 * @property Carbon|null $retention_until
 * @property int|null $uploaded_by
 * @property Carbon|null $available_at
 * @property Carbon|null $revoked_at
 */
class UploadedFile extends Model
{
    /** @use HasFactory<UploadedFileFactory> */
    use HasFactory;

    protected $table = 'uploaded_files';

    /**
     * Creation-time safe attributes only. Status, paths, hashes and scope are set
     * explicitly by the file-domain pipeline/transitions — never mass-assigned, and
     * status is never settable from a request.
     *
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'branch_id',
        'owner_user_id',
        'purpose',
        'storage_disk',
        'quarantine_path',
        'original_filename_encrypted',
        'safe_download_filename',
        'declared_mime_type',
        'detected_mime_type',
        'extension',
        'size_bytes',
        'sha256',
        'retention_until',
        'uploaded_by',
    ];

    /** Storage internals + hash never leave the server. @var list<string> */
    protected $hidden = [
        'storage_disk',
        'quarantine_path',
        'final_path',
        'sha256',
        'original_filename_encrypted',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'scan_status' => 'pending',
        'lifecycle_status' => 'quarantined',
    ];

    /** @return Factory<UploadedFile> */
    protected static function newFactory(): Factory
    {
        return UploadedFileFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (UploadedFile $file): void {
            if (! isset($file->ulid)) {
                $file->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => FilePurpose::class,
            'scan_status' => FileScanStatus::class,
            'lifecycle_status' => FileLifecycleStatus::class,
            'original_filename_encrypted' => 'encrypted',
            'size_bytes' => 'integer',
            'retention_until' => 'datetime',
            'available_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    // --- Relations -----------------------------------------------------------

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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<FileScanEvent, $this> */
    public function scanEvents(): HasMany
    {
        return $this->hasMany(FileScanEvent::class);
    }

    // --- Read helpers --------------------------------------------------------

    public function isDownloadable(): bool
    {
        return $this->lifecycle_status === FileLifecycleStatus::Available
            && $this->scan_status === FileScanStatus::Clean
            && $this->final_path !== null;
    }

    public function isTerminal(): bool
    {
        return in_array($this->lifecycle_status, [
            FileLifecycleStatus::Revoked,
            FileLifecycleStatus::Expired,
            FileLifecycleStatus::Deleted,
        ], true);
    }

    // --- Sanctioned lifecycle transitions (called only by file-domain services) --

    /** Record a clean scan result (stays quarantined until finalized). */
    public function markScanClean(): void
    {
        $this->assertScanFrom(FileScanStatus::Pending);
        $this->forceFill(['scan_status' => FileScanStatus::Clean->value])->save();
    }

    /** Record an infected scan; the file can never become available. */
    public function markInfected(): void
    {
        $this->assertScanFrom(FileScanStatus::Pending);
        $this->forceFill(['scan_status' => FileScanStatus::Infected->value])->save();
    }

    /** Record a scan failure (transient/engine error); retryable, never available. */
    public function markScanFailed(): void
    {
        $this->forceFill(['scan_status' => FileScanStatus::ScanFailed->value])->save();
    }

    /** Reject at validation time (before/instead of scanning). */
    public function markRejected(): void
    {
        $this->forceFill(['scan_status' => FileScanStatus::Rejected->value])->save();
    }

    /** Promote a clean, quarantined file to available with its final path. */
    public function markAvailable(string $finalPath): void
    {
        if ($this->scan_status !== FileScanStatus::Clean) {
            throw new \DomainException('Cannot make a non-clean file available.');
        }
        if ($this->lifecycle_status !== FileLifecycleStatus::Quarantined) {
            throw new \DomainException('Only a quarantined file can become available.');
        }
        $this->forceFill([
            'final_path' => $finalPath,
            'lifecycle_status' => FileLifecycleStatus::Available->value,
            'available_at' => now(),
        ])->save();
    }

    public function markLifecycle(FileLifecycleStatus $status): void
    {
        $attributes = ['lifecycle_status' => $status->value];
        if ($status === FileLifecycleStatus::Revoked) {
            $attributes['revoked_at'] = now();
        }
        $this->forceFill($attributes)->save();
    }

    private function assertScanFrom(FileScanStatus $expected): void
    {
        if ($this->scan_status !== $expected) {
            throw new \DomainException(
                "Invalid scan transition from {$this->scan_status->value} (expected {$expected->value})."
            );
        }
    }
}
