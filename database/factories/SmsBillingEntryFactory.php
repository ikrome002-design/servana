<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\SmsBillingEntry;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SmsBillingEntry>
 *
 * Default: a `provisional` entry for one segment to one recipient. `amount_minor` is DERIVED from
 * quantity × unit cost rather than supplied independently, because the
 * `sms_billing_entries_amount_product_check` DB CHECK enforces exactly that relationship
 * (ADR-005) — a factory that supplied an inconsistent amount would simply fail to insert.
 */
class SmsBillingEntryFactory extends Factory
{
    protected $model = SmsBillingEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $quantity = 1;
        $unitCostMinor = 100;

        return [
            'ulid' => (string) Str::ulid(),
            'campaign_id' => PersonnelSmsCampaign::factory(),
            'merchant_id' => fn (array $a) => PersonnelSmsCampaign::query()->whereKey($a['campaign_id'])->value('merchant_id'),
            'branch_id' => fn (array $a) => PersonnelSmsCampaign::query()->whereKey($a['campaign_id'])->value('branch_id'),
            'quantity' => $quantity,
            'unit_cost_minor' => $unitCostMinor,
            'amount_minor' => $quantity * $unitCostMinor,
            'currency' => Currency::KES->value,
            'status' => SmsBillingEntryStatus::Provisional,
            'billing_invoice_line_id' => null,
        ];
    }

    public function billable(): static
    {
        return $this->state(fn (array $a): array => ['status' => SmsBillingEntryStatus::Billable]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $a): array => ['status' => SmsBillingEntryStatus::Cancelled]);
    }
}
