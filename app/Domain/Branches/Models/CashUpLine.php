<?php

declare(strict_types=1);

namespace App\Domain\Branches\Models;

use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\CashUpLineFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CashUpLine — per-method line of a branch cash-up (Plan §45; Phase 18B). Branch-owned
 * via its cash-up parent (no ulid — child evidence row). Concrete methods only (never
 * split_payment). expected_minor server-derived; counted_minor Branch Manager input.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $cash_up_id
 * @property PaymentMethod $method
 * @property int $expected_minor
 * @property int $counted_minor
 * @property int $variance_minor
 */
class CashUpLine extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<CashUpLineFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'cash_up_id',
        'method',
        'expected_minor',
        'counted_minor',
        'variance_minor',
    ];

    /** @return Factory<CashUpLine> */
    protected static function newFactory(): Factory
    {
        return CashUpLineFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'expected_minor' => 'integer',
            'counted_minor' => 'integer',
            'variance_minor' => 'integer',
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

    /** @return BelongsTo<BranchCashUp, $this> */
    public function cashUp(): BelongsTo
    {
        return $this->belongsTo(BranchCashUp::class, 'cash_up_id');
    }
}
