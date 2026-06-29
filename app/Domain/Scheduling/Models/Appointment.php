<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Services\AppointmentStateMachine;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Appointment — Front-Office-owned booked appointment (Plan §13.7, §36, §25.2;
 * Phase 16A). Branch-owned; the ULID is the public id + searchable reference and
 * is used for route binding; the internal bigint id is never exposed. Personnel
 * may be unassigned at creation. Status transitions go through
 * {@see AppointmentStateMachine} + the domain
 * actions — never assigned directly.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $client_id
 * @property int $service_id
 * @property int|null $preferred_personnel_staff_profile_id
 * @property int|null $assigned_personnel_staff_profile_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property AppointmentStatus $status
 * @property string|null $cancellation_reason
 * @property string|null $transfer_reason
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $no_show_at
 * @property int|null $created_by
 */
class Appointment extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'client_id',
        'service_id',
        'preferred_personnel_staff_profile_id',
        'assigned_personnel_staff_profile_id',
        'starts_at',
        'ends_at',
        'status',
        'cancellation_reason',
        'transfer_reason',
        'checked_in_at',
        'cancelled_at',
        'no_show_at',
        'created_by',
    ];

    /** @return Factory<Appointment> */
    protected static function newFactory(): Factory
    {
        return AppointmentFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment): void {
            if (! isset($appointment->ulid)) {
                $appointment->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
            'status' => AppointmentStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @param Builder<Appointment> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (AppointmentStatus $s): string => $s->value,
            AppointmentStatus::reservingStatuses(),
        ));
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
    public function preferredPersonnel(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'preferred_personnel_staff_profile_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function assignedPersonnel(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'assigned_personnel_staff_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
