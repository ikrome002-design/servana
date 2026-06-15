<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Notifications\StaffInvitationNotification;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Create and send a staff invitation (Scope §3.4). The raw token is generated
 * here, hashed at rest (Plan §3 rule 14), and embedded only in the emailed link.
 * Duplicate pending invitations are blocked by the DB partial unique index; the
 * request validates this first for a clean 422.
 */
final class CreateStaffInvitation
{
    public const EXPIRY_HOURS = 72;

    /**
     * @param  array{role: MerchantUserRole, role_title?: ?string, service_eligibility_ids?: ?array<int, int>}  $data
     */
    public function handle(Merchant $merchant, MerchantBranch $branch, User $actor, string $email, array $data): StaffInvitation
    {
        $email = Str::lower(trim($email));
        $rawToken = $this->generateRawToken();

        $invitation = StaffInvitation::query()->create([
            'merchant_id' => $merchant->id,
            'branch_id' => $branch->id,
            'email' => $email,
            'role' => $data['role'],
            'role_title' => $data['role_title'] ?? null,
            'service_eligibility_ids' => $data['service_eligibility_ids'] ?? null,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHours(self::EXPIRY_HOURS),
            'resend_count' => 0,
            'last_sent_at' => now(),
            'invited_by' => $actor->id,
        ]);

        $this->send($invitation, $rawToken, $merchant, $branch);

        return $invitation;
    }

    /** Re-send a fresh token for a pending invitation, returning the new instance. */
    public function rotateAndSend(StaffInvitation $invitation): StaffInvitation
    {
        $rawToken = $this->generateRawToken();

        $invitation->token_hash = hash('sha256', $rawToken);
        $invitation->expires_at = now()->addHours(self::EXPIRY_HOURS);
        $invitation->resend_count = $invitation->resend_count + 1;
        $invitation->last_sent_at = now();
        $invitation->save();

        /** @var Merchant $merchant */
        $merchant = $invitation->merchant;
        /** @var MerchantBranch $branch */
        $branch = $invitation->branch;
        $this->send($invitation, $rawToken, $merchant, $branch);

        return $invitation->refresh();
    }

    private function send(StaffInvitation $invitation, string $rawToken, Merchant $merchant, MerchantBranch $branch): void
    {
        Notification::route('mail', $invitation->email)->notify(
            new StaffInvitationNotification(
                $rawToken,
                $merchant->name,
                $branch->name,
                $this->roleLabel($invitation->role),
            ),
        );
    }

    private function roleLabel(MerchantUserRole $role): string
    {
        return match ($role) {
            MerchantUserRole::BranchManager => 'Branch Manager',
            MerchantUserRole::Hr => 'Human Resource manager',
            MerchantUserRole::Finance => 'Finance officer',
            MerchantUserRole::FrontOffice => 'Front Office',
            MerchantUserRole::Personnel => 'Personnel',
            MerchantUserRole::Audit => 'Audit',
            MerchantUserRole::MerchantAdmin => 'Administrator',
        };
    }

    /** 64 cryptographically secure random bytes, base64url-encoded. */
    private function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }
}
