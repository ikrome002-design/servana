<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\PromotionalDiscountType;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PromotionalDiscountFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * PromotionalDiscount — platform-governed promotional discount (Plan §53; Phase 20C).
 * PLATFORM-SCOPED (no merchant/branch ownership; TenantOwnership::EXEMPT). ULID is the
 * public route key. `value` is basis points for `percentage` (≤10000), minor units for
 * `fixed_amount`. Lifecycle via PromotionalDiscountStateMachine; approved terms are
 * immutable.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property PromotionalDiscountType $type
 * @property int $value
 * @property string|null $currency
 * @property PromotionTargetScope $target_scope
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property PromotionStatus $status
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $change_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PromotionalDiscount extends Model
{
    /** @use HasFactory<PromotionalDiscountFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'value',
        'currency',
        'target_scope',
        'effective_from',
        'effective_to',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'change_reason',
    ];

    /** @return Factory<PromotionalDiscount> */
    protected static function newFactory(): Factory
    {
        return PromotionalDiscountFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PromotionalDiscount $discount): void {
            if (! isset($discount->ulid)) {
                $discount->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PromotionalDiscountType::class,
            'value' => 'integer',
            'target_scope' => PromotionTargetScope::class,
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => PromotionStatus::class,
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<PromotionalDiscountTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PromotionalDiscountTarget::class, 'promotional_discount_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
