<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Enums\ConsentChannel;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\ClientConsentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Client SMS-consent state (Plan §13.7, §35; 15A/21S).
 *
 * Branch-owned. One current state per (client, channel) — a unique constraint
 * backs that; consent changes update the row + `changed_at`. No SMS delivery in
 * Phase 15A.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $client_id
 * @property ConsentChannel $channel
 * @property ConsentState $state
 * @property string $source
 * @property Carbon $changed_at
 * @property int|null $created_by
 */
class ClientConsent extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<ClientConsentFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'client_id',
        'channel',
        'state',
        'source',
        'changed_at',
        'created_by',
    ];

    /** @return Factory<ClientConsent> */
    protected static function newFactory(): Factory
    {
        return ClientConsentFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => ConsentChannel::class,
            'state' => ConsentState::class,
            'changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
