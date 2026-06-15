<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A default grant: this role grants this permission key (Plan §10.3).
 *
 * @property int $id
 * @property int $role_id
 * @property int $permission_id
 */
class RolePermissionAssignment extends Model
{
    protected $fillable = [
        'role_id',
        'permission_id',
    ];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Permission, $this> */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
