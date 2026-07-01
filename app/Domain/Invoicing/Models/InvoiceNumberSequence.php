<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Services\InvoiceNumberAllocator;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\InvoiceNumberSequenceFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceNumberSequence — gap-free per-merchant invoice-number counter (Plan
 * §13.8/§13.15, §40; Phase 17). Tenant-owned (merchant_id, no branch_id —
 * numbering is merchant-wide). Consumed only inside a successful finalization
 * transaction under a row lock (see {@see InvoiceNumberAllocator}).
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $scope
 * @property int $next_value
 * @property string|null $prefix
 */
class InvoiceNumberSequence extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<InvoiceNumberSequenceFactory> */
    use HasFactory;

    public const SCOPE_MERCHANT_CLIENT_INVOICE = 'merchant_client_invoice';

    protected $fillable = [
        'merchant_id',
        'scope',
        'next_value',
        'prefix',
    ];

    /** @return Factory<InvoiceNumberSequence> */
    protected static function newFactory(): Factory
    {
        return InvoiceNumberSequenceFactory::new();
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
