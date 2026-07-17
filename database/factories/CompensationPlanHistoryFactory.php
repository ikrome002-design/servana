<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Models\CompensationPlanHistory;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompensationPlanHistory>
 *
 * Default: the `created` event of a plan. Derives merchant/branch/subject/effective_from from
 * the plan so every composite FK holds. Append-only — a written row is never updated or
 * deleted (DB trigger). Records CONFIGURATION changes only; never money.
 */
class CompensationPlanHistoryFactory extends Factory
{
    protected $model = CompensationPlanHistory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'compensation_plan_id' => PersonnelCompensationPlan::factory(),
            'merchant_id' => fn (array $attributes) => PersonnelCompensationPlan::query()
                ->whereKey($attributes['compensation_plan_id'])->value('merchant_id'),
            'branch_id' => fn (array $attributes) => PersonnelCompensationPlan::query()
                ->whereKey($attributes['compensation_plan_id'])->value('branch_id'),
            'staff_profile_id' => fn (array $attributes) => PersonnelCompensationPlan::query()
                ->whereKey($attributes['compensation_plan_id'])->value('staff_profile_id'),
            'event' => CompensationPlanHistoryEvent::Created,
            'from_status' => null,
            'to_status' => CompensationPlanStatus::Draft,
            'changed_fields' => null,
            'was_backdated' => false,
            'change_reason' => 'Initial compensation plan.',
            'actor_user_id' => User::factory(),
            'effective_from' => fn (array $attributes) => PersonnelCompensationPlan::query()
                ->whereKey($attributes['compensation_plan_id'])->value('effective_from'),
            'created_at' => now(),
        ];
    }

    /**
     * A lifecycle transition event. `created` is the only event with no prior status
     * (DB event/from_status CHECK).
     */
    public function event(
        CompensationPlanHistoryEvent $event,
        CompensationPlanStatus $from,
        CompensationPlanStatus $to,
    ): static {
        return $this->state(fn (array $attributes): array => [
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
        ]);
    }

    public function submitted(): static
    {
        return $this->event(
            CompensationPlanHistoryEvent::Submitted,
            CompensationPlanStatus::Draft,
            CompensationPlanStatus::PendingApproval,
        );
    }

    public function approved(): static
    {
        return $this->event(
            CompensationPlanHistoryEvent::Approved,
            CompensationPlanStatus::PendingApproval,
            CompensationPlanStatus::Active,
        );
    }

    public function superseded(): static
    {
        return $this->event(
            CompensationPlanHistoryEvent::Superseded,
            CompensationPlanStatus::Active,
            CompensationPlanStatus::Superseded,
        );
    }

    /** F8: the recorded version was a backdated change. */
    public function backdated(): static
    {
        return $this->state(fn (array $attributes): array => ['was_backdated' => true]);
    }

    /** @param array<string, mixed> $fields */
    public function changedFields(array $fields): static
    {
        return $this->state(fn (array $attributes): array => ['changed_fields' => $fields]);
    }
}
