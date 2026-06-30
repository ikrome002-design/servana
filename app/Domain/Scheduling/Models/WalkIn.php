<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\WalkInFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * WalkIn — a Front-Office-owned walk-in client (Plan §13.7, §37; Phase 16B).
 *
 * Branch-owned; the ULID is the public id + route key. Creating a walk-in
 * atomically attaches/creates a branch-scoped client (via the existing Phase 15A
 * client action — no duplicated logic) and spawns exactly one {@see QueueEntry}.
 * No preferred-personnel fee exists here (Phase 20A).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int|null $client_id
 * @property int $service_id
 * @property QueueAssignmentMode $assignment_mode
 * @property int|null $preferred_personnel_staff_profile_id
 * @property int|null $created_by
 */
class WalkIn extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<WalkInFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'client_id',
        'service_id',
        'assignment_mode',
        'preferred_personnel_staff_profile_id',
        'created_by',
    ];

    /** @return Factory<WalkIn> */
    protected static function newFactory(): Factory
    {
        return WalkInFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (WalkIn $walkIn): void {
            if (! isset($walkIn->ulid)) {
                $walkIn->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'assignment_mode' => QueueAssignmentMode::class,
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

    /** @return HasOne<QueueEntry, $this> */
    public function queueEntry(): HasOne
    {
        return $this->hasOne(QueueEntry::class, 'walk_in_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
