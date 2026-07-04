<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Merchants\Models\Merchant;
use App\Domain\Receipts\Models\ReceiptNumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceiptNumberSequence>
 */
class ReceiptNumberSequenceFactory extends Factory
{
    protected $model = ReceiptNumberSequence::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'scope' => 'receipt',
            'next_value' => 1,
            'prefix' => null,
        ];
    }
}
