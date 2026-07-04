<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use App\Domain\FinanceOps\Enums\FinanceExportType;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FinanceExport>
 *
 * Defaults to a merchant-wide queued receipts export.
 */
class FinanceExportFactory extends Factory
{
    protected $model = FinanceExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'branch_id' => null,
            'requested_by' => User::factory(),
            'export_type' => FinanceExportType::Receipts,
            'scope_json' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
            'reason' => 'Monthly reconciliation.',
            'status' => FinanceExportStatus::Queued,
            'file_id' => null,
            'row_count' => null,
            'expires_at' => null,
            'first_downloaded_at' => null,
            'last_downloaded_at' => null,
            'download_count' => 0,
            'failure_code' => null,
            'failure_message_redacted' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FinanceExportStatus::Ready,
            'row_count' => 10,
            'expires_at' => now()->addDay(),
        ]);
    }
}
