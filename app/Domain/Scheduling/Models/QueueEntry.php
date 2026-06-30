<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\QueueEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * QueueEntry — the operational branch queue position (Plan §13.7, §25.2, §37;
 * Phase 16B). Branch-owned; the ULID is the public id + route key. Originates from
 * exactly one source (a {@see WalkIn} or a checked-in {@see Appointment}). Status
 * transitions go through {@see QueueEntryStateMachine} + the named domain actions —
 * never assigned directly. Phase 16B creates NO service session/invoice.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int|null $walk_in_id
 * @property int|null $appointment_id
 * @property int $client_id
 * @property int $service_id
 * @property int|null $staff_profile_id
 * @property int|null $preferred_personnel_staff_profile_id
 * @property QueueAssignmentMode $assignment_mode
 * @property QueueEntryStatus $status
 * @property int $position
 * @property Carbon $queued_at
 * @property Carbon|null $assigned_at
 * @property Carbon|null $called_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $no_show_at
 * @property Carbon|null $transferred_at
 * @property int|null $transferred_from_staff_profile_id
 * @property int|null $transferred_to_staff_profile_id
 * @property string|null $transfer_reason
 * @property string|null $cancellation_reason
 * @property string|null $preferred_personnel_override_reason
 * @property int $estimated_wait_minutes
 * @property int|null $estimated_wait_override_minutes
 * @property string|null $estimated_wait_override_reason
 * @property int|null $estimated_wait_overridden_by
 * @property int|null $created_by
 */
class QueueEntry extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<QueueEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'walk_in_id',
        'appointment_id',
        'client_id',
        'service_id',
        'staff_profile_id',
        'preferred_personnel_staff_profile_id',
        'assignment_mode',
        'status',
        'position',
        'queued_at',
        'assigned_at',
        'called_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'no_show_at',
        'transferred_at',
        'transferred_from_staff_profile_id',
        'transferred_to_staff_profile_id',
        'transfer_reason',
        'cancellation_reason',
        'preferred_personnel_override_reason',
        'estimated_wait_minutes',
        'estimated_wait_override_minutes',
        'estimated_wait_override_reason',
        'estimated_wait_overridden_by',
        'created_by',
    ];

    /** @return Factory<QueueEntry> */
    protected static function newFactory(): Factory
    {
        return QueueEntryFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (QueueEntry $entry): void {
            if (! isset($entry->ulid)) {
                $entry->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'assignment_mode' => QueueAssignmentMode::class,
            'status' => QueueEntryStatus::class,
            'position' => 'integer',
            'estimated_wait_minutes' => 'integer',
            'estimated_wait_override_minutes' => 'integer',
            'queued_at' => 'datetime',
            'assigned_at' => 'datetime',
            'called_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
            'transferred_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Active (queue-occupying) entries — block branch day close / archival.
     *
     * @param  Builder<QueueEntry>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::statusValues(QueueEntryStatus::activeStatuses()));
    }

    /**
     * Ordered active entries (carry a branch position).
     *
     * @param  Builder<QueueEntry>  $query
     */
    public function scopeOrderedActive(Builder $query): void
    {
        $query->whereIn('status', self::statusValues(QueueEntryStatus::orderedActiveStatuses()));
    }

    /**
     * @param  list<QueueEntryStatus>  $statuses
     * @return list<string>
     */
    public static function statusValues(array $statuses): array
    {
        return array_map(static fn (QueueEntryStatus $s): string => $s->value, $statuses);
    }

    /** The effective wait shown to the operator (override wins if present). */
    public function effectiveWaitMinutes(): int
    {
        return $this->estimated_wait_override_minutes ?? $this->estimated_wait_minutes;
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

    /** @return BelongsTo<WalkIn, $this> */
    public function walkIn(): BelongsTo
    {
        return $this->belongsTo(WalkIn::class, 'walk_in_id');
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function assignedPersonnel(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function preferredPersonnel(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'preferred_personnel_staff_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The service session this entry produced (Phase 16C). At most one (the
     * `UNIQUE (queue_entry_id)` index enforces it).
     *
     * @return HasOne<ServiceSession, $this>
     */
    public function serviceSession(): HasOne
    {
        return $this->hasOne(ServiceSession::class, 'queue_entry_id');
    }
}
