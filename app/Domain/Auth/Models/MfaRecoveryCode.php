<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use App\Domain\Auth\Mfa\RecoveryCodeManager;
use App\Models\User;
use Database\Factories\MfaRecoveryCodeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A one-time MFA recovery code (Plan §13.5, §18; Phase R3).
 *
 * Stored only as a SHA-256 hash; the raw code is shown once at generation and
 * never persisted/logged. Single-use is enforced atomically in
 * {@see RecoveryCodeManager}.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $code_hash
 * @property Carbon|null $used_at
 */
class MfaRecoveryCode extends Model
{
    /** @use HasFactory<MfaRecoveryCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    /** @return Factory<MfaRecoveryCode> */
    protected static function newFactory(): Factory
    {
        return MfaRecoveryCodeFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (MfaRecoveryCode $code): void {
            if (! isset($code->ulid)) {
                $code->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
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
}
