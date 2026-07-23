<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Support\SmsMessageSegmentCalculator;
use App\Enums\Currency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<PersonnelSmsCampaign>
 *
 * Default: a `draft` campaign anchored on one branch so every composite FK agrees on the merchant.
 * Character/segment counts are computed by the real {@see SmsMessageSegmentCalculator} rather than
 * hardcoded, so a factory row can never disagree with production arithmetic.
 */
class PersonnelSmsCampaignFactory extends Factory
{
    protected $model = PersonnelSmsCampaign::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $body = 'Thank you for visiting us today. We look forward to seeing you again soon.';
        $measurement = (new SmsMessageSegmentCalculator)->measure($body);

        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $a) => MerchantBranch::query()->whereKey($a['branch_id'])->value('merchant_id'),
            'staff_profile_id' => fn (array $a) => StaffProfile::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'primary_branch_id' => $a['branch_id'],
            ])->id,
            'message_body_encrypted' => $body,
            'message_template_id' => null,
            'recipient_count' => 0,
            'message_character_count' => $measurement->characterCount,
            'segment_count' => $measurement->segmentCount,
            'estimated_cost_minor' => 0,
            'final_cost_minor' => null,
            'currency' => Currency::KES->value,
            'status' => PersonnelSmsCampaignStatus::Draft,
            'failure_reason_code' => null,
            'consent_snapshot_at' => null,
            'created_by' => fn () => User::factory()->create()->id,
            'confirmed_at' => null,
            'queued_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
        ];
    }

    /** A confirmed campaign (consent snapshotted, at least one recipient, nothing queued yet). */
    public function confirmed(int $recipientCount = 1, int $estimatedCostMinor = 100): static
    {
        return $this->state(fn (array $a): array => [
            'status' => PersonnelSmsCampaignStatus::Confirmed,
            'recipient_count' => $recipientCount,
            'estimated_cost_minor' => $estimatedCostMinor,
            'consent_snapshot_at' => Carbon::now(),
            'confirmed_at' => Carbon::now(),
        ]);
    }

    public function queued(int $recipientCount = 1, int $estimatedCostMinor = 100): static
    {
        return $this->confirmed($recipientCount, $estimatedCostMinor)
            ->state(fn (array $a): array => [
                'status' => PersonnelSmsCampaignStatus::Queued,
                'queued_at' => Carbon::now(),
            ]);
    }

    public function sending(int $recipientCount = 1, int $estimatedCostMinor = 100): static
    {
        return $this->queued($recipientCount, $estimatedCostMinor)
            ->state(fn (array $a): array => ['status' => PersonnelSmsCampaignStatus::Sending]);
    }
}
