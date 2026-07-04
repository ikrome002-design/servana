<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\CashUpLine;
use App\Domain\Payments\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashUpLine>
 *
 * Anchors on a branch_cash_up and mirrors its branch/merchant.
 */
class CashUpLineFactory extends Factory
{
    protected $model = CashUpLine::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cash_up_id' => BranchCashUp::factory(),
            'merchant_id' => fn (array $attributes) => BranchCashUp::query()
                ->whereKey($attributes['cash_up_id'])->value('merchant_id'),
            'branch_id' => fn (array $attributes) => BranchCashUp::query()
                ->whereKey($attributes['cash_up_id'])->value('branch_id'),
            'method' => PaymentMethod::Cash,
            'expected_minor' => 0,
            'counted_minor' => 0,
            'variance_minor' => 0,
        ];
    }
}
