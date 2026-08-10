<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\PlatformAccess\Enums\PlatformAccessInvitationStatus;
use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Models\PlatformAccessInvitation;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\PlatformAccess\Support\PlatformAccessInvitationToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invite a person to internal Citrus Labs platform access (COR-UI08-001 §11; Phase UI-08).
 *
 * ENUMERATION-SAFE BY CONSTRUCTION. The action performs the same work whether or not the address is
 * already known, and returns the same shape either way: an existing pending invitation is rotated
 * rather than rejected, and an address that already holds active access yields a "no new invitation"
 * result the controller renders identically to a fresh invite. Nothing in the response, its timing
 * or its status code discloses whether a user exists.
 *
 * MAGIC LINK ONLY. Acceptance issues an ordinary host-bound Magic Link; no password, OTP or WebAuthn
 * credential is created anywhere in this path.
 *
 * The raw token is returned to the CALLER for delivery and is never persisted — only its SHA-256
 * digest reaches the database.
 */
final class InvitePlatformAdministrator
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return array{invitation:PlatformAccessInvitation|null,raw_token:string|null,already_active:bool}
     */
    public function handle(string $email, string $reason, User $actor, string $environment): array
    {
        $normalized = Str::lower(trim($email));

        return DB::transaction(function () use ($normalized, $reason, $actor, $environment): array {
            // Already an active administrator: nothing to invite, and saying so to the caller would
            // confirm the address exists. The controller renders this identically to a new invite.
            $alreadyActive = PlatformAccessMembership::query()
                ->where('status', PlatformAccessStatus::Active->value)
                ->whereHas('user', static fn ($query) => $query->where('email', $normalized))
                ->exists();

            if ($alreadyActive) {
                return ['invitation' => null, 'raw_token' => null, 'already_active' => true];
            }

            $token = PlatformAccessInvitationToken::generate();

            $existing = PlatformAccessInvitation::query()
                ->where('email', $normalized)
                ->where('status', PlatformAccessInvitationStatus::Pending->value)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // Rotate rather than refuse: the partial unique index permits one live invitation
                // per address, and a rotation invalidates the previous link immediately.
                $existing->forceFill([
                    'token_hash' => $token->hash,
                    'expires_at' => now()->addHours(PlatformAccessInvitation::EXPIRY_HOURS),
                    'resend_count' => $existing->resend_count + 1,
                    'last_sent_at' => now(),
                ])->save();

                $invitation = $existing;
            } else {
                $invitation = PlatformAccessInvitation::query()->create([
                    'email' => $normalized,
                    'role_key' => PlatformAccessMembership::ROLE_SUPER_ADMIN,
                    'purpose' => PlatformAccessInvitation::PURPOSE,
                    'environment' => $environment,
                    'token_hash' => $token->hash,
                    'status' => PlatformAccessInvitationStatus::Pending,
                    'invited_by_user_id' => $actor->id,
                    'expires_at' => now()->addHours(PlatformAccessInvitation::EXPIRY_HOURS),
                    'last_sent_at' => now(),
                ]);
            }

            $this->audit->record(AuditEvent::PlatformInternalAccessInvited, $actor, null, null, $invitation, [
                'invitation_id' => $invitation->ulid,
                'email' => PlatformAccessInvitationToken::maskEmail($normalized),
                'reason' => $reason,
            ]);

            return ['invitation' => $invitation, 'raw_token' => $token->raw, 'already_active' => false];
        });
    }
}
