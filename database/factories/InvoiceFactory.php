<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 *
 * Anchors on a branch and derives a same-branch + same-merchant client so the
 * composite consistency FKs hold. Defaults to a `draft` (no number, no
 * finalized_at, zero money — coherent with the draft/arithmetic CHECKs). State
 * helpers set a coherent finalized snapshot (number + finalized_at + total =
 * subtotal + preferred + tax - discount).
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            'client_id' => fn (array $attributes) => Client::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'merchant_id' => $attributes['merchant_id'],
            ])->id,
            'invoice_number' => null,
            'status' => InvoiceStatus::Draft,
            'previous_status' => null,
            'subtotal_minor' => 0,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'preferred_personnel_fee_snapshot_minor' => null,
            'total_minor' => 0,
            'validated_paid_minor' => 0,
            'currency' => 'KES',
            'percentage_fee_config_snapshot' => null,
            'finalized_at' => null,
            'created_by' => null,
        ];
    }

    /** A finalized, issued invoice with a coherent number + totals. */
    public function issued(int $subtotalMinor = 500000): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Issued,
            'invoice_number' => 'INV-'.$this->faker->unique()->numerify('######'),
            'subtotal_minor' => $subtotalMinor,
            'total_minor' => $subtotalMinor,
            'finalized_at' => CarbonImmutable::now(),
        ]);
    }

    public function voidPending(): static
    {
        return $this->issued()->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::VoidPending,
            'previous_status' => InvoiceStatus::Issued,
            'void_reason' => 'Duplicate of an earlier invoice.',
        ]);
    }

    public function voided(): static
    {
        return $this->issued()->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Voided,
            'voided_at' => CarbonImmutable::now(),
            'voided_by' => null,
            'void_reason' => 'Duplicate of an earlier invoice.',
        ]);
    }

    public function adjusted(): static
    {
        return $this->issued()->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Adjusted,
            'adjusted_at' => CarbonImmutable::now(),
            'adjusted_by' => null,
            'adjustment_reason' => 'Corrected service line.',
        ]);
    }
}
