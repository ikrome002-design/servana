<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Sessions\Models\AccountContextHandoff;
use App\Domain\Sessions\Models\SessionFamily;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountContextHandoff>
 */
class AccountContextHandoffFactory extends Factory
{
    protected $model = AccountContextHandoff::class;

    /**
     * Defaults describe a PLATFORM target (the only account allowed a null target merchant by
     * `account_context_handoffs_platform_merchant_check`). The hash is a random digest, never a
     * hash of a token this factory hands back — production code is the only thing that ever holds
     * a raw handoff token.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'token_hash' => hash('sha256', Str::random(64)),
            'user_id' => User::factory(),
            'source_session_family_id' => SessionFamily::factory(),
            'source_host_session_id' => null,
            'source_account_key' => 'merchant_personnel',
            'target_account_key' => 'super_administrator',
            'target_host' => 'citrus.servana.test',
            'environment' => 'testing',
            'target_merchant_id' => null,
            'target_merchant_user_id' => null,
            'target_branch_id' => null,
            'redirect_path' => null,
            'expires_at' => now()->addSeconds(120),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinutes(8),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'consumed_at' => now(),
        ]);
    }
}
