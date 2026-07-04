<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\FinanceOps\Enums\FinanceDisputeStatus;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FinanceDispute>
 *
 * Anchors on a branch (via an issued invoice), derives merchant, links the invoice.
 */
class FinanceDisputeFactory extends Factory
{
    protected $model = FinanceDispute::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $invoice = Invoice::factory()->issued();

        return [
            'ulid' => (string) Str::ulid(),
            'invoice_id' => $invoice,
            'merchant_id' => fn (array $attributes) => Invoice::query()
                ->whereKey($attributes['invoice_id'])->value('merchant_id'),
            'branch_id' => fn (array $attributes) => Invoice::query()
                ->whereKey($attributes['invoice_id'])->value('branch_id'),
            'payment_record_id' => null,
            'status' => FinanceDisputeStatus::Open,
            'reason' => 'Client disputes the charged amount.',
            'resolution_note' => null,
            'evidence_file_id' => null,
            'created_by' => User::factory(),
            'resolved_by' => null,
        ];
    }
}
