<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Models;

use App\Domain\Messaging\Sms\Enums\SmsDeliveryAttemptStatus;
use App\Domain\Messaging\Sms\Enums\SmsProviderResultClass;
use App\Domain\Messaging\Sms\Support\SmsProviderPayloadRedactor;
use Database\Factories\SmsDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SmsDeliveryAttempt — one append-only provider attempt against a recipient (Plan §13.13, §64,
 * §24.5; Phase 21S).
 *
 * `provider_message_redacted` is passed through {@see SmsProviderPayloadRedactor} before
 * persistence and bounded to 512 characters by the column; a DB CHECK additionally rejects any
 * value containing a run of 7+ digits, so a phone number cannot survive a redactor regression.
 * Request headers, API keys and message bodies are never stored here at all.
 *
 * Scope is inherited via `recipient_id`; the table is never route-bound and no Resource exposes it.
 * `sms_delivery_attempts_append_only` blocks every UPDATE and DELETE.
 *
 * @property int $id
 * @property int $recipient_id
 * @property int $attempt_number
 * @property string $provider
 * @property SmsDeliveryAttemptStatus $status
 * @property SmsProviderResultClass $result_class
 * @property string|null $provider_code
 * @property string|null $provider_message_redacted
 * @property int|null $duration_ms
 * @property Carbon $attempted_at
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $created_at
 */
class SmsDeliveryAttempt extends Model
{
    /** @use HasFactory<SmsDeliveryAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'sms_delivery_attempts';

    protected $fillable = [
        'recipient_id',
        'attempt_number',
        'provider',
        'status',
        'result_class',
        'provider_code',
        'provider_message_redacted',
        'duration_ms',
        'attempted_at',
        'next_retry_at',
    ];

    /** @return Factory<SmsDeliveryAttempt> */
    protected static function newFactory(): Factory
    {
        return SmsDeliveryAttemptFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'status' => SmsDeliveryAttemptStatus::class,
            'result_class' => SmsProviderResultClass::class,
            'duration_ms' => 'integer',
            'attempted_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PersonnelSmsRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(PersonnelSmsRecipient::class, 'recipient_id');
    }
}
