<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Enums\ClientStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Client record — Front-Office owned (Plan §13.7, §35; guardrail §6.4; 15A).
 *
 * Branch-owned. Contact is ENCRYPTED at rest (`phone_encrypted` / `email_encrypted`
 * via the AES-256-GCM `encrypted` cast) and DISPLAYED MASKED (`phone_last_four`).
 * `phone_index` is a keyed HMAC blind index used ONLY for branch-scoped equality
 * search + duplicate prevention — it is `$hidden`, never serialized, never logged.
 * No hard delete; `status` is active/archived.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property string $full_name
 * @property string $phone_encrypted decrypted plaintext (encrypted at rest)
 * @property string $phone_index HMAC blind index (never exposed)
 * @property string $phone_last_four
 * @property string|null $email_encrypted decrypted plaintext (encrypted at rest)
 * @property string|null $notes
 * @property int|null $created_by
 * @property ClientStatus $status
 */
class Client extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'full_name',
        'phone_encrypted',
        'phone_index',
        'phone_last_four',
        'email_encrypted',
        'notes',
        'created_by',
        'status',
    ];

    /**
     * Sensitive columns never serialize. The blind index and ciphertext must not
     * reach any Resource, log, or array/JSON representation.
     *
     * @var list<string>
     */
    protected $hidden = [
        'phone_encrypted',
        'phone_index',
        'email_encrypted',
    ];

    /** @return Factory<Client> */
    protected static function newFactory(): Factory
    {
        return ClientFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client): void {
            if (! isset($client->ulid)) {
                $client->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // AES-256-GCM at rest (APP_KEY); decrypted transparently on read.
            'phone_encrypted' => 'encrypted',
            'email_encrypted' => 'encrypted',
            'status' => ClientStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** Masked phone for display (e.g. "••• ••• 1234") — never the full number. */
    public function maskedPhone(): string
    {
        return '••• ••• '.$this->phone_last_four;
    }

    /** Masked email for display (e.g. "a••@example.com") or null. */
    public function maskedEmail(): ?string
    {
        $email = $this->email_encrypted;
        if ($email === null || ! str_contains($email, '@')) {
            return $email === null ? null : '•••';
        }

        [$local, $domain] = explode('@', $email, 2);
        $first = $local === '' ? '' : $local[0];

        return $first.'••@'.$domain;
    }

    /** @param Builder<Client> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ClientStatus::Active->value);
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

    /** @return HasMany<ClientConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(ClientConsent::class, 'client_id');
    }
}
