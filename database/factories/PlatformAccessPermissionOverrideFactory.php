<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\Models\Permission;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\PlatformAccess\Models\PlatformAccessPermissionOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformAccessPermissionOverride>
 *
 * `effect` has no state helper because there is no other effect: the column is CHECK-constrained to
 * `deny`, and a factory offering a `grant()` state would imply a capability that cannot exist.
 */
class PlatformAccessPermissionOverrideFactory extends Factory
{
    protected $model = PlatformAccessPermissionOverride::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'platform_access_membership_id' => PlatformAccessMembership::factory(),
            'permission_id' => fn (): int => (int) Permission::query()->where('category', 'platform')->value('id'),
            'effect' => PlatformAccessPermissionOverride::EFFECT_DENY,
            'reason' => 'Scoped down by a factory state.',
            'created_by_user_id' => User::factory()->state(['is_platform_staff' => true]),
        ];
    }
}
