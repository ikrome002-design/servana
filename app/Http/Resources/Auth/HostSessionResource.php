<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Domain\Sessions\Models\HostSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user's own active session, sanitized for display (Phase UI-03; UI/UX plan §5.2, §18.7).
 *
 * WHAT IS DELIBERATELY ABSENT: the raw Laravel session id, the family's internal id, the IP
 * address, the user-agent string, and any permission or role grant. A session list is a security
 * screen, not a telemetry dump — a leaked screenshot of it must not help anyone.
 *
 * The device/browser label is omitted entirely rather than derived from a truncated user agent:
 * UI/UX plan §5.2 allows a device label only if it can be produced without exposing raw
 * user-agent or IP data, and nothing in the schema can do that today. Recording the gap is more
 * honest than shipping a fingerprint with a friendly name.
 *
 * @mixin HostSession
 */
final class HostSessionResource extends JsonResource
{
    private ?string $currentSessionId = null;

    /**
     * Tell the resource which Laravel session is the caller's own, so `is_current` can be decided
     * server-side. Passed in explicitly rather than read from the request, because the resource
     * must never need access to a session id it is not allowed to emit.
     */
    public function currentSessionId(?string $sessionId): self
    {
        $this->currentSessionId = $sessionId;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HostSession $session */
        $session = $this->resource;

        $currentSessionId = $this->currentSessionId;

        return [
            'id' => $session->ulid,
            'account_key' => $session->account_key,
            'host' => $session->host,
            'merchant_name' => $session->merchant?->name,
            'branch_name' => $session->branch?->name,
            'created_at' => $session->created_at?->toIso8601String(),
            'last_activity_at' => $session->last_activity_at->toIso8601String(),
            'revoked' => $session->revoked_at !== null,
            // Compared server-side; the raw id is never sent either way.
            'is_current' => is_string($currentSessionId) && hash_equals($session->session_id, $currentSessionId),
        ];
    }
}
