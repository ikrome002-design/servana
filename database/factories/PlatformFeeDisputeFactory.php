<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformFeeDispute>
 */
class PlatformFeeDisputeFactory extends Factory
{
    protected $model = PlatformFeeDispute::class;

    /**
     * Default: an open dispute over a ledger entry.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'branch_id' => null,
            'platform_fee_ledger_entry_id' => PlatformFeeLedgerEntry::factory(),
            'subscription_invoice_id' => null,
            'reason' => 'The percentage fee basis appears incorrect for this invoice.',
            'status' => PlatformFeeDisputeStatus::Open,
            'assigned_reviewer' => null,
            'evidence_file_id' => null,
            'resolution_note' => null,
            'created_by' => User::factory(),
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }

    public function forEntry(PlatformFeeLedgerEntry $entry): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform_fee_ledger_entry_id' => $entry->id,
            'merchant_id' => $entry->merchant_id,
            'branch_id' => $entry->branch_id,
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PlatformFeeDisputeStatus::Open,
            'assigned_reviewer' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'resolution_note' => null,
        ]);
    }

    public function underReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PlatformFeeDisputeStatus::UnderReview,
            'assigned_reviewer' => $attributes['assigned_reviewer'] ?? User::factory(),
            'resolved_by' => null,
            'resolved_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PlatformFeeDisputeStatus::Resolved,
            'assigned_reviewer' => $attributes['assigned_reviewer'] ?? User::factory(),
            'resolution_note' => 'Reviewed; basis corrected via adjustment.',
            'resolved_by' => $attributes['resolved_by'] ?? User::factory(),
            'resolved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PlatformFeeDisputeStatus::Rejected,
            'assigned_reviewer' => $attributes['assigned_reviewer'] ?? User::factory(),
            'resolution_note' => 'Dispute rejected; fee basis is correct.',
            'resolved_by' => $attributes['resolved_by'] ?? User::factory(),
            'resolved_at' => now(),
        ]);
    }
}
