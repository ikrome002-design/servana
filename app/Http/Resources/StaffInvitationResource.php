<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Hr\Models\StaffInvitation;
use App\Http\Resources\Concerns\HasCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe staff-invitation payload (Scope §3.4). The token hash is NEVER exposed —
 * only invitation metadata. ULID is the public id.
 *
 * @mixin StaffInvitation
 */
final class StaffInvitationResource extends JsonResource
{
    use HasCapabilities;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_title' => $this->role_title,
            'branch_id' => $this->branch?->ulid,
            'status' => $this->status->value,
            'resend_count' => $this->resend_count,
            'expires_at' => $this->expires_at->toIso8601String(),
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'can' => $this->capabilities($request, [
                'manage' => 'manage',
            ]),
        ];
    }
}
