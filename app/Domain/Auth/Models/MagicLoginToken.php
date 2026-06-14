<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use Database\Factories\MagicLoginTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Magic Link token (Plan §7.1 magic_login_tokens, §9.1).
 *
 * Stores only the SHA-256 hash of the raw token (`token_hash`); the raw token
 * exists only transiently in the request that issues the email. Consumption is
 * performed by MagicLinkTokenService via an atomic UPDATE, not by mutating this
 * model, so single-use cannot be lost to a read-modify-write race.
 *
 * @property int $id
 * @property string $ulid
 * @property string $email
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $invalidated_at
 * @property string|null $ip_address
 * @property string|null $user_agent_hash
 */
class MagicLoginToken extends Model
{
    /** @use HasFactory<MagicLoginTokenFactory> */
    use HasFactory;

    protected $table = 'magic_login_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'email',
        'token_hash',
        'expires_at',
        'consumed_at',
        'invalidated_at',
        'ip_address',
        'user_agent_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MagicLoginToken $token): void {
            if (! isset($token->ulid)) {
                $token->ulid = (string) Str::ulid();
            }
        });
    }
}
