<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditLog>
 *
 * TEST-ONLY. Production audit rows are written exclusively by DatabaseAuditRecorder
 * (which computes the hash chain); this factory fabricates a syntactically valid,
 * branch-scoped source row for flagged-event / masked-read tests that need an existing
 * audit record but do not verify the chain. Chain-verification tests build real chains
 * through the recorder, never this factory.
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $branch = MerchantBranch::factory();

        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => $branch,
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            'actor_id' => null,
            'actor_label' => 'system',
            'action' => 'branch.profile_updated',
            'severity' => AuditSeverity::Info,
            'auditable_type' => null,
            'auditable_id' => null,
            'context' => ['old_values' => ['name' => 'A'], 'new_values' => ['name' => 'B']],
            'ip_address' => null,
            'correlation_id' => (string) Str::ulid(),
            'previous_hash' => null,
            'hash' => hash('sha256', (string) Str::ulid()),
        ];
    }
}
