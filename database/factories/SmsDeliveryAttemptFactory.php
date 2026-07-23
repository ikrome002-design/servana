<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Messaging\Sms\Enums\SmsDeliveryAttemptStatus;
use App\Domain\Messaging\Sms\Enums\SmsProviderResultClass;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Models\SmsDeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SmsDeliveryAttempt>
 *
 * Default: a first, accepted attempt with NO provider message at all. The default deliberately
 * contains no digits — the `sms_delivery_attempts_redaction_check` DB CHECK rejects any stored
 * message carrying a run of 7+ digits, so a careless factory default must not be the thing that
 * discovers it.
 */
class SmsDeliveryAttemptFactory extends Factory
{
    protected $model = SmsDeliveryAttempt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipient_id' => PersonnelSmsRecipient::factory(),
            'attempt_number' => 1,
            'provider' => 'fake',
            'status' => SmsDeliveryAttemptStatus::Accepted,
            'result_class' => SmsProviderResultClass::Accepted,
            'provider_code' => 'accepted',
            'provider_message_redacted' => null,
            'duration_ms' => 1,
            'attempted_at' => Carbon::now(),
            'next_retry_at' => null,
        ];
    }

    public function transientFailure(SmsProviderResultClass $class = SmsProviderResultClass::ProviderError): static
    {
        return $this->state(fn (array $a): array => [
            'status' => SmsDeliveryAttemptStatus::TransientFailure,
            'result_class' => $class,
            'provider_code' => $class->value,
            'next_retry_at' => Carbon::now()->addMinute(),
        ]);
    }

    public function permanentFailure(SmsProviderResultClass $class = SmsProviderResultClass::InvalidRecipient): static
    {
        return $this->state(fn (array $a): array => [
            'status' => SmsDeliveryAttemptStatus::PermanentFailure,
            'result_class' => $class,
            'provider_code' => $class->value,
            'next_retry_at' => null,
        ]);
    }
}
