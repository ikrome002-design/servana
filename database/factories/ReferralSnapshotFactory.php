<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Integrations\ReferEarn\Enums\ReferralCaptureChannel;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReferralSnapshot>
 *
 * Default: a freshly `captured` query-param snapshot with a well-formed code and no landing
 * metadata. The raw code is set in plaintext and encrypted by the model cast.
 */
class ReferralSnapshotFactory extends Factory
{
    protected $model = ReferralSnapshot::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = 'SERVANA-'.Str::upper(Str::random(5));

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'raw_code_encrypted' => $code,
            'code_normalized' => $code,
            'capture_channel' => ReferralCaptureChannel::QueryParam,
            'captured_at' => now(),
            'landing_metadata' => null,
            'snapshot_status' => ReferralSnapshotStatus::Captured,
            're_validation_result_code' => null,
            're_attribution_public_id' => null,
            'confirmed_at' => null,
            'last_transition_at' => now(),
        ];
    }

    public function validating(): self
    {
        return $this->state(fn (): array => ['snapshot_status' => ReferralSnapshotStatus::Validating]);
    }

    public function validated(): self
    {
        return $this->state(fn (): array => [
            'snapshot_status' => ReferralSnapshotStatus::Validated,
            're_validation_result_code' => 'VALID',
        ]);
    }

    public function confirmed(): self
    {
        return $this->state(fn (): array => [
            'snapshot_status' => ReferralSnapshotStatus::Confirmed,
            're_validation_result_code' => 'VALID',
            're_attribution_public_id' => 'ATTR-'.Str::upper(Str::random(10)),
            'confirmed_at' => now(),
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (): array => [
            'snapshot_status' => ReferralSnapshotStatus::Rejected,
            're_validation_result_code' => 'ATTRIBUTION_CONFLICT',
        ]);
    }

    /** A malformed submission: normalized code MUST be null (DB CHECK). */
    public function invalidFormat(): self
    {
        return $this->state(fn (): array => [
            'snapshot_status' => ReferralSnapshotStatus::InvalidFormat,
            'code_normalized' => null,
            'capture_channel' => ReferralCaptureChannel::ManualEntry,
        ]);
    }
}
