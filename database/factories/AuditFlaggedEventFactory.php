<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Audit\Enums\AuditFlaggedEventStatus;
use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditFlaggedEvent>
 *
 * Anchors on a branch-scoped audit_logs row and inherits its merchant + branch, so the
 * flag's tenant identity always matches the audited source (Plan §13.2; Phase 19).
 */
class AuditFlaggedEventFactory extends Factory
{
    protected $model = AuditFlaggedEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $auditLog = AuditLog::factory();

        return [
            'ulid' => (string) Str::ulid(),
            'audit_log_id' => $auditLog,
            'merchant_id' => fn (array $attributes) => AuditLog::query()
                ->whereKey($attributes['audit_log_id'])->value('merchant_id'),
            'branch_id' => fn (array $attributes) => AuditLog::query()
                ->whereKey($attributes['audit_log_id'])->value('branch_id'),
            'status' => AuditFlaggedEventStatus::Open,
            'review_notes' => null,
            'assigned_to' => null,
            'resolved_by' => null,
            'created_by' => User::factory(),
        ];
    }

    public function underReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AuditFlaggedEventStatus::UnderReview,
            'assigned_to' => User::factory(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AuditFlaggedEventStatus::Resolved,
            'assigned_to' => User::factory(),
            'resolved_by' => User::factory(),
            'review_notes' => 'Reviewed; benign configuration change.',
        ]);
    }
}
