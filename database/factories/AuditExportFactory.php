<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditExport>
 *
 * Branch-owned: anchors on a MerchantBranch so merchant_id + branch_id always agree
 * (Plan §13.5; Phase 19; ADR-010). Default state is a `queued` request.
 */
class AuditExportFactory extends Factory
{
    protected $model = AuditExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Active merchant so a tenant-aware generation job can rehydrate its context.
        $merchant = Merchant::factory()->active()->create();
        $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => $branch->id,
            'merchant_id' => $merchant->id,
            'requested_by_user_id' => User::factory(),
            'reason' => 'Quarterly branch audit review.',
            'scope_json' => ['domains' => ['general'], 'severities' => []],
            'status' => AuditExportStatus::Queued,
            'download_count' => 0,
            'requested_at' => now(),
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AuditExportStatus::Processing,
            'processing_started_at' => now(),
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AuditExportStatus::Ready,
            'processing_started_at' => now()->subMinute(),
            'generated_at' => now(),
            'expires_at' => now()->addDays(30),
            'row_count' => 5,
            'file_id' => UploadedFile::factory()->state([
                'merchant_id' => $attributes['merchant_id'],
                'branch_id' => $attributes['branch_id'],
                'purpose' => FilePurpose::AuditExport->value,
                'scan_status' => 'clean',
                'lifecycle_status' => 'available',
                'final_path' => 'exports/audit/'.Str::ulid().'.csv',
                'available_at' => now(),
            ]),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AuditExportStatus::Failed,
            'processing_started_at' => now()->subMinute(),
            'failed_at' => now(),
            'failure_code' => 'generation_failed',
            'failure_message_redacted' => 'Audit export generation failed.',
        ]);
    }

    public function revoked(): static
    {
        return $this->ready()->state(fn (array $attributes) => [
            'status' => AuditExportStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->ready()->state(fn (array $attributes) => [
            'status' => AuditExportStatus::Expired,
            'expires_at' => now()->subDay(),
            'expired_at' => now(),
        ]);
    }
}
