<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Hr\Models\StaffProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 23 §14.1 — the MINIMAL personnel option the Branch Manager needs to drive the
 * read-only personnel-schedule picker (product-owner decision: a branch-dashboard-scoped
 * read, NOT the HR staff roster).
 *
 * Exposes ONLY the public-safe personnel ULID and the display name. It deliberately does
 * NOT reuse StaffProfileResource, which carries `phone`, `role`, `status`,
 * `employment_*`, `primary_branch_id` and a capability map — that resource leaking through
 * an unauthorized `GET /api/v1/staff` is the very defect this endpoint closes (Plan §9.1
 * personnel-contact extraction; RK-05).
 *
 * The field is named `id` (not `ulid`) because `id` is the public identifier the staff
 * contract already exposes and the schedule workflow already passes to
 * `GET /api/v1/staff/{staff}/availability`; adding a second alias for the same value was
 * explicitly rejected.
 *
 * @mixin StaffProfile
 */
final class BranchPersonnelOptionResource extends JsonResource
{
    /** @return array<string, string> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'display_name' => $this->display_name,
        ];
    }
}
