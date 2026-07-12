<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionTargetType;
use App\Domain\Merchants\Models\Merchant;
use Carbon\CarbonImmutable;
use Database\Factories\PromotionalDiscountTargetFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PromotionalDiscountTarget — one explicit normalized target row (Plan §53; Phase 20C).
 * Exactly one of merchant_id / subscription_plan_id / billing_mode is set, matching
 * `target_type` (DB CHECK). The immutable `ulid` is the deterministic resolver tie-break
 * key (Gate C1). PLATFORM-SCOPED (TenantOwnership::EXEMPT). Append-only (created_at only).
 *
 * @property int $id
 * @property string $ulid
 * @property int $promotional_discount_id
 * @property PromotionTargetType $target_type
 * @property int|null $merchant_id
 * @property int|null $subscription_plan_id
 * @property BillingMode|null $billing_mode
 * @property CarbonImmutable|null $created_at
 */
class PromotionalDiscountTarget extends Model
{
    /** @use HasFactory<PromotionalDiscountTargetFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'promotional_discount_id',
        'target_type',
        'merchant_id',
        'subscription_plan_id',
        'billing_mode',
    ];

    /** @return Factory<PromotionalDiscountTarget> */
    protected static function newFactory(): Factory
    {
        return PromotionalDiscountTargetFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PromotionalDiscountTarget $target): void {
            if (! isset($target->ulid)) {
                $target->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_type' => PromotionTargetType::class,
            'billing_mode' => BillingMode::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<PromotionalDiscount, $this> */
    public function promotionalDiscount(): BelongsTo
    {
        return $this->belongsTo(PromotionalDiscount::class, 'promotional_discount_id');
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
