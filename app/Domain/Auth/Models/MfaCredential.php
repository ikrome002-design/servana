<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use App\Models\User;
use Database\Factories\MfaCredentialFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A user's TOTP authenticator (Plan §13.5, §18; Phase R3).
 *
 * Identity-owned (no merchant scope). The TOTP secret is encrypted at rest via
 * the `encrypted` cast — the plaintext secret is never persisted/logged and is
 * only surfaced once, through the authenticated enrollment-start response.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $type
 * @property string $secret_encrypted decrypted plaintext secret (encrypted at rest)
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $last_used_at
 * @property int|null $last_used_timestep
 */
class MfaCredential extends Model
{
    /** @use HasFactory<MfaCredentialFactory> */
    use HasFactory;

    public const TYPE_TOTP = 'totp';

    protected $fillable = [
        'user_id',
        'type',
        'secret_encrypted',
        'confirmed_at',
        'last_used_at',
        'last_used_timestep',
    ];

    /** Never expose the secret in array/JSON serialization. */
    protected $hidden = [
        'secret_encrypted',
    ];

    /** @return Factory<MfaCredential> */
    protected static function newFactory(): Factory
    {
        return MfaCredentialFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (MfaCredential $credential): void {
            if (! isset($credential->ulid)) {
                $credential->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // AES-256-GCM at rest (APP_KEY); decrypted transparently on read.
            'secret_encrypted' => 'encrypted',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_used_timestep' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A credential is usable for challenge only once enrollment is confirmed. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
