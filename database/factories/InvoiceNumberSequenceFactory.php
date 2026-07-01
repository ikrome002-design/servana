<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceNumberSequence>
 */
class InvoiceNumberSequenceFactory extends Factory
{
    protected $model = InvoiceNumberSequence::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'scope' => InvoiceNumberSequence::SCOPE_MERCHANT_CLIENT_INVOICE,
            'next_value' => 1,
            'prefix' => null,
        ];
    }
}
