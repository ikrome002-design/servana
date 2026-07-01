<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InvoiceItem>
 *
 * Anchors on an invoice and a COMPLETED service session in the SAME branch +
 * merchant (Gate A), deriving service/personnel from the session so every
 * composite consistency FK holds. line_total_minor = unit_price_minor * quantity
 * to satisfy the arithmetic CHECK.
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $invoice = fn (array $attributes) => Invoice::query()->whereKey($attributes['invoice_id'])->firstOrFail();

        return [
            'ulid' => (string) Str::ulid(),
            'invoice_id' => Invoice::factory(),
            'merchant_id' => fn (array $attributes) => $invoice($attributes)->merchant_id,
            'branch_id' => fn (array $attributes) => $invoice($attributes)->branch_id,
            'service_session_id' => fn (array $attributes) => ServiceSession::factory()->completed()->create([
                'branch_id' => $attributes['branch_id'],
                'merchant_id' => $attributes['merchant_id'],
                'client_id' => $invoice($attributes)->client_id,
            ])->id,
            'service_id' => fn (array $attributes) => ServiceSession::query()
                ->whereKey($attributes['service_session_id'])->value('service_id'),
            'staff_profile_id' => fn (array $attributes) => ServiceSession::query()
                ->whereKey($attributes['service_session_id'])->value('staff_profile_id'),
            'description' => 'Service',
            'quantity' => 1,
            'unit_price_minor' => 500000,
            'line_total_minor' => fn (array $attributes) => $attributes['unit_price_minor'] * $attributes['quantity'],
            'preferred_personnel_fee_minor' => null,
            'eligible_for_commission' => false,
            'currency' => 'KES',
        ];
    }
}
