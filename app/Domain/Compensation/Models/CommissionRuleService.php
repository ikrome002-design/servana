<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\CommissionRuleServiceFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * CommissionRuleService — one selected-services membership row for a commission rule (Plan §61;
 * §9.1; Phase 20G; table `commission_rule_services`). Branch-owned configuration substrate;
 * append-only (`created_at` only) — memberships are added/removed only while the rule is draft
 * (DB guard), never updated. Carries NO money. Immutability + same-branch consistency are
 * DB-enforced by triggers and composite FKs.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $commission_rule_id
 * @property int $service_id
 * @property Carbon|null $created_at
 */
class CommissionRuleService extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<CommissionRuleServiceFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'commission_rule_id',
        'service_id',
    ];

    /** @return Factory<CommissionRuleService> */
    protected static function newFactory(): Factory
    {
        return CommissionRuleServiceFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (CommissionRuleService $membership): void {
            if (! isset($membership->ulid)) {
                $membership->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
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

    /** @return BelongsTo<CommissionRule, $this> */
    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
