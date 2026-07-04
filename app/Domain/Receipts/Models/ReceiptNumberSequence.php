<?php

declare(strict_types=1);

namespace App\Domain\Receipts\Models;

use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\ReceiptNumberSequenceFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ReceiptNumberSequence — per-merchant gap-free receipt numbering counter
 * (Plan §13.15; §43; Phase 18B). Tenant-owned (merchant-wide numbering).
 *
 * The next value is allocated under SELECT … FOR UPDATE inside the receipt-issuance
 * transaction; MAX()+1 is never used.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $scope
 * @property int $next_value
 * @property string|null $prefix
 */
class ReceiptNumberSequence extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<ReceiptNumberSequenceFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'scope',
        'next_value',
        'prefix',
    ];

    /** @return Factory<ReceiptNumberSequence> */
    protected static function newFactory(): Factory
    {
        return ReceiptNumberSequenceFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
        ];
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
