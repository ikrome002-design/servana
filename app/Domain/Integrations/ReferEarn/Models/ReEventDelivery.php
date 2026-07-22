<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Models;

use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryResponseClass;
use Database\Factories\ReEventDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ReEventDelivery — one append-only delivery attempt against an outbox event (Plan §13.17, §58A.2;
 * Phase 21R-A; table `re_event_deliveries`).
 *
 * The stored response body is truncated to 512 characters AND redacted before persistence
 * (Plan §24.5). Request headers, signatures, nonces and payloads are never stored here at all.
 * Scope is inherited via `re_outbound_event_id`; the table is never route-bound.
 *
 * @property int $id
 * @property int $re_outbound_event_id
 * @property Carbon $attempted_at
 * @property int $duration_ms
 * @property int|null $response_status
 * @property ReDeliveryResponseClass $response_class
 * @property string|null $error_code
 * @property string|null $response_body_truncated_redacted
 * @property Carbon|null $created_at
 */
class ReEventDelivery extends Model
{
    /** @use HasFactory<ReEventDeliveryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 're_event_deliveries';

    protected $fillable = [
        're_outbound_event_id',
        'attempted_at',
        'duration_ms',
        'response_status',
        'response_class',
        'error_code',
        'response_body_truncated_redacted',
    ];

    /** @return Factory<ReEventDelivery> */
    protected static function newFactory(): Factory
    {
        return ReEventDeliveryFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'duration_ms' => 'integer',
            'response_status' => 'integer',
            'response_class' => ReDeliveryResponseClass::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReOutboundEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ReOutboundEvent::class, 're_outbound_event_id');
    }
}
