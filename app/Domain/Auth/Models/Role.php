<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A fixed catalogue role (Plan §10.1). `key` mirrors merchant_users.role for the
 * seven merchant roles, plus the platform `super_admin`.
 *
 * @property int $id
 * @property string $ulid
 * @property string $key
 * @property string $name
 * @property string $scope
 * @property bool $is_read_only
 * @property string|null $description
 */
class Role extends Model
{
    protected $fillable = [
        'key',
        'name',
        'scope',
        'is_read_only',
        'description',
    ];

    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            if (! isset($role->ulid)) {
                $role->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_read_only' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission_assignments')->withTimestamps();
    }
}
