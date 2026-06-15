<?php

declare(strict_types=1);

namespace App\Domain\Auth\Seeders;

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Role;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Database\Seeder;

/**
 * Materialises the canonical PermissionRegistry into the database (Plan §10.3):
 * the permission catalogue, the role catalogue, and the default-grant matrix.
 *
 * Idempotent — safe to re-run. PermissionMatrixTest verifies the seeded
 * role_permission_assignments equal the §10.3 matrix exactly (zero mismatches).
 */
final class PermissionSeeder extends Seeder
{
    public function run(PermissionRegistry $registry): void
    {
        $this->seedPermissions($registry);
        $this->seedRoles($registry);
        $this->seedAssignments($registry);
    }

    private function seedPermissions(PermissionRegistry $registry): void
    {
        foreach ($registry->permissions() as $key => $meta) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                [
                    'category' => $meta['category'],
                    'description' => $meta['description'],
                    'is_mutating' => $meta['mutating'],
                ],
            );
        }
    }

    private function seedRoles(PermissionRegistry $registry): void
    {
        foreach ($registry->roles() as $key => $meta) {
            Role::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $meta['name'],
                    'scope' => $meta['scope'],
                    'is_read_only' => $meta['read_only'],
                    'description' => $meta['description'],
                ],
            );
        }
    }

    private function seedAssignments(PermissionRegistry $registry): void
    {
        $permissionIds = Permission::query()->pluck('id', 'key');

        foreach ($registry->roleKeys() as $roleKey) {
            /** @var Role $role */
            $role = Role::query()->where('key', $roleKey)->firstOrFail();

            $desired = [];
            foreach ($registry->defaultGrantsFor($roleKey) as $permissionKey) {
                $permissionId = $permissionIds[$permissionKey] ?? null;
                if ($permissionId !== null) {
                    $desired[] = $permissionId;
                }
            }

            // Sync default grants exactly so a removed cell is also removed on re-seed.
            $role->permissions()->sync($desired);
        }
    }
}
