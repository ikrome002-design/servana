<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\PlatformAccess\Models\PlatformAccessInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform-access invitation (COR-UI08-001 §11.6; Phase UI-08).
 *
 * THE TOKEN AND ITS HASH NEVER APPEAR HERE. `token_hash` is `$hidden` on the model and is not
 * referenced by this Resource at all; the raw token exists only inside the emailed link. What the
 * page needs is the lifecycle — who was invited, by whom, when it expires, and how many times it
 * has been resent.
 *
 * @mixin PlatformAccessInvitation
 */
final class PlatformAccessInvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'email' => $this->email,
            'role_key' => $this->role_key,
            'status' => $this->status->value,
            'redeemable' => $this->isRedeemable(),
            'environment' => $this->environment,
            'invited_by' => $this->invitedBy?->ulid,
            'expires_at' => $this->expires_at->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,
            'resend_count' => $this->resend_count,
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
        ];
    }
}
