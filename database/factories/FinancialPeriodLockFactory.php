<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FinancialPeriodLock>
 *
 * Defaults to a merchant-wide (branch_id null) `locked` period covering last month.
 */
class FinancialPeriodLockFactory extends Factory
{
    protected $model = FinancialPeriodLock::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = CarbonImmutable::now('Africa/Nairobi')->subMonth()->startOfMonth();

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'branch_id' => null,
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'status' => FinancialPeriodLockStatus::Locked,
            'exception_required' => false,
            'locked_by' => User::factory(),
            'locked_at' => CarbonImmutable::now(),
            'reopen_requested_by' => null,
            'reopen_requested_at' => null,
            'reopen_reason' => null,
            'reopen_approved_by' => null,
            'reopen_approved_at' => null,
            'reopened_by' => null,
            'reopened_at' => null,
        ];
    }

    public function exceptionRequired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'exception_required' => true,
        ]);
    }
}
