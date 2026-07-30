<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Models\SessionFamily;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HostSession>
 */
class HostSessionFactory extends Factory
{
    protected $model = HostSession::class;

    /**
     * The default is the PLATFORM context, because it is the only account whose
     * `host_sessions_platform_merchant_check` allows a null merchant. Merchant-side states must
     * name their merchant explicitly — the CHECK makes an accidental "Finance session with no
     * merchant" impossible rather than merely unlikely.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'session_family_id' => SessionFamily::factory(),
            'user_id' => User::factory(),
            'session_id' => Str::random(40),
            'account_key' => 'super_administrator',
            'host' => 'citrus.servana.test',
            'environment' => 'testing',
            'merchant_id' => null,
            'merchant_user_id' => null,
            'branch_id' => null,
            'mfa_required_at_creation' => true,
            'last_activity_at' => now(),
        ];
    }

    public function revoked(SessionRevocationReason $reason = SessionRevocationReason::GlobalLogout): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }
}
