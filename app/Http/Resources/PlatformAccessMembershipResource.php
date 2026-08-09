<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An internal platform administrator as the roster shows them (COR-UI08-001 §11; Phase UI-08).
 *
 * WHAT IS DELIBERATELY ABSENT: no token, no token hash, no session id, no MFA secret, no recovery
 * code, and no device or IP history. "Active sessions" is a COUNT from the existing session-family
 * surface — enough to decide whether to revoke, never enough to reconstruct someone's movements.
 *
 * The email is shown in full, because a roster whose whole purpose is to say who holds platform
 * authority is unusable if it cannot name them — and reaching it already requires
 * `platform.internal_access.view`, an MFA-mandatory platform key held by `super_admin` alone.
 *
 * @mixin PlatformAccessMembership
 */
final class PlatformAccessMembershipResource extends JsonResource
{
    public function __construct(
        PlatformAccessMembership $resource,
        private readonly int $activeSessionCount = 0,
        private readonly bool $mfaEnrolled = false,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->user;

        return [
            'id' => $this->ulid,
            'user' => [
                'id' => $user?->ulid,
                'email' => $user?->email,
                'name' => $user?->name,
                'status' => $user?->status,
                // MFA enrolment is EVIDENCE the roster needs to judge risk, resolved through the
                // existing MfaManager rather than by reading credential columns here.
                'mfa_enrolled' => $this->mfaEnrolled,
                'last_login_at' => $user?->last_login_at?->toIso8601String(),
            ],
            'role_key' => $this->role_key,
            'status' => $this->status->value,
            'grants_access' => $this->status->grantsAccess(),
            'active_session_count' => $this->activeSessionCount,
            'denied_permissions' => $this->whenLoaded(
                'permissionOverrides',
                fn () => $this->permissionOverrides
                    ->map(static fn ($override): ?string => $override->permission?->key)
                    ->filter()
                    ->sort()
                    ->values()
                    ->all(),
                [],
            ),
            'invited_at' => $this->invited_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'last_action' => $this->last_action,
            'last_action_reason' => $this->last_action_reason,
            'last_action_at' => $this->last_action_at?->toIso8601String(),
        ];
    }
}
