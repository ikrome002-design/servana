<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\AuditFlaggedEventStatus;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\AuditFlaggedEventFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * AuditFlaggedEvent — the Audit-role review record over one branch-scoped audit_logs
 * row (Plan §13.2, §80; Phase 19). Branch-owned; ULID is the public id + route key.
 *
 * NEVER mutates the audited source row — audit_logs is append-only + hash-chained. Only
 * review metadata (status/review_notes/assigned_to/resolved_by) transitions, through the
 * flagged-event state machine. See docs/architecture/state-machines/audit-flagged-event.md.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $audit_log_id
 * @property AuditFlaggedEventStatus $status
 * @property string|null $review_notes
 * @property int|null $assigned_to
 * @property int|null $resolved_by
 * @property int $created_by
 */
class AuditFlaggedEvent extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<AuditFlaggedEventFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'audit_log_id',
        'status',
        'review_notes',
        'assigned_to',
        'resolved_by',
        'created_by',
    ];

    /** @return Factory<AuditFlaggedEvent> */
    protected static function newFactory(): Factory
    {
        return AuditFlaggedEventFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (AuditFlaggedEvent $event): void {
            if (! isset($event->ulid)) {
                $event->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AuditFlaggedEventStatus::class,
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

    /** @return BelongsTo<AuditLog, $this> */
    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class, 'audit_log_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
