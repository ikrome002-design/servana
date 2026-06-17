<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Models;

use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\MerchantProfileFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Editable business profile, 1:1 with a merchant (Plan §7.1, Scope §3.2 step 2).
 *
 * A shell row is created at registration; first-time setup fills the fields.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property string|null $business_category
 * @property string|null $logo_path
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $receipt_display_name
 * @property string|null $address
 * @property string|null $town
 * @property string $country
 * @property string $timezone
 */
class MerchantProfile extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantProfileFactory> */
    use HasFactory;

    /** @return Factory<MerchantProfile> */
    protected static function newFactory(): Factory
    {
        return MerchantProfileFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'business_category',
        'logo_path',
        'contact_email',
        'contact_phone',
        'receipt_display_name',
        'address',
        'town',
        'country',
        'timezone',
    ];

    protected static function booted(): void
    {
        static::creating(function (MerchantProfile $profile): void {
            if (! isset($profile->ulid)) {
                $profile->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
