<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A capability registry key (Plan §10.3). Seeded from PermissionRegistry.
 *
 * @property int $id
 * @property string $ulid
 * @property string $key
 * @property string $category
 * @property string $description
 * @property bool $is_mutating
 */
class Permission extends Model
{
    protected $fillable = [
        'key',
        'category',
        'description',
        'is_mutating',
    ];

    protected static function booted(): void
    {
        static::creating(function (Permission $permission): void {
            if (! isset($permission->ulid)) {
                $permission->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_mutating' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission_assignments');
    }
}
