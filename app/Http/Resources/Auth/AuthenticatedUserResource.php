<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal authenticated-user bootstrap payload for the SPA authStore
 * (Plan §6.2). Public identifier is the ULID (A5) — the bigint PK never leaves.
 *
 * `memberships` and `permissions` are empty in Phase 5: the merchant tenancy
 * model (Phase 6) and the permission registry (Phase 8) do not exist yet. They
 * are returned as empty arrays so the frontend contract is stable across phases.
 *
 * @mixin User
 */
final class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            // Phase 6 / Phase 8 integration points — intentionally empty for now.
            'memberships' => [],
            'permissions' => [],
            'is_platform_staff' => false,
        ];
    }
}
