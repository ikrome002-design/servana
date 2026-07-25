<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Services\ServiceSessionStateMachine;
use App\Domain\Search\Concerns\SearchableDocument;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\ServiceSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ServiceSession — the unit of service delivery (Plan §13.7, §25.2; Phase 16C).
 * Branch-owned; the ULID is the public id + route key. Always originates from a
 * {@see QueueEntry} ({@see $queue_entry_id}; appointment provenance is preserved
 * through the queue entry's `appointment_id`). The performed {@see $service_id} is
 * snapshotted from the locked source queue entry. Status transitions go through
 * {@see ServiceSessionStateMachine} + the named domain actions — never assigned
 * directly. Phase 16C creates NO invoice/payment/commission ledger.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int|null $queue_entry_id
 * @property int $client_id
 * @property int $service_id
 * @property int $staff_profile_id
 * @property ServiceSessionStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $notes
 * @property bool|null $preferred_personnel_honored
 * @property int|null $created_by
 */
class ServiceSession extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;
    /** @use HasFactory<ServiceSessionFactory> */
    use HasFactory;

    use SearchableDocument;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'queue_entry_id',
        'client_id',
        'service_id',
        'staff_profile_id',
        'status',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'notes',
        'preferred_personnel_honored',
        'created_by',
    ];

    /** @return Factory<ServiceSession> */
    protected static function newFactory(): Factory
    {
        return ServiceSessionFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (ServiceSession $session): void {
            if (! isset($session->ulid)) {
                $session->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ServiceSessionStatus::class,
            'preferred_personnel_honored' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Active (work-occupying) sessions — block branch day close / archival and
     * project the assigned personnel as busy.
     *
     * @param  Builder<ServiceSession>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', ServiceSessionStatus::values(ServiceSessionStatus::activeStatuses()));
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

    /** @return BelongsTo<QueueEntry, $this> */
    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class, 'queue_entry_id');
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
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
