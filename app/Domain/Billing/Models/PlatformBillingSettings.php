<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingMode;
use App\Models\User;
use App\Support\Casts\JsonObject;
use Database\Factories\PlatformBillingSettingsFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PlatformBillingSettings — platform-scoped, effective-dated billing configuration
 * (Plan §13.9, §47, §50; Phase 20A). Platform-owned. An append-only version series: the
 * current version is the greatest `effective_from <= now()`; an update inserts a NEW version
 * and never mutates a prior one. Financial primitives are first-class columns; only documented
 * keys live in `settings`. The ULID is the public id + route key.
 *
 * @property int $id
 * @property string $ulid
 * @property BillingMode $billing_mode
 * @property int $default_trial_days
 * @property int $grace_days
 * @property string $currency
 * @property int $updated_by
 * @property Carbon $effective_from
 * @property array<string, mixed> $settings
 */
class PlatformBillingSettings extends Model
{
    /** @use HasFactory<PlatformBillingSettingsFactory> */
    use HasFactory;

    protected $table = 'platform_billing_settings';

    protected $fillable = [
        'billing_mode',
        'default_trial_days',
        'grace_days',
        'currency',
        'updated_by',
        'effective_from',
        'settings',
    ];

    /** @return Factory<PlatformBillingSettings> */
    protected static function newFactory(): Factory
    {
        return PlatformBillingSettingsFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformBillingSettings $settings): void {
            if (! isset($settings->ulid)) {
                $settings->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_mode' => BillingMode::class,
            'default_trial_days' => 'integer',
            'grace_days' => 'integer',
            'effective_from' => 'datetime',
            'settings' => JsonObject::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * The current effective settings version: the greatest `effective_from <= now()`.
     * Returns null before any version exists (the seeded launch default fills this).
     */
    public static function current(): ?self
    {
        return self::query()
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
