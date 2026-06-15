<?php

declare(strict_types=1);

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Staff invitation email (Scope §3.4). Carries the raw acceptance token in the
 * accept link only; the token is never persisted (only its hash) and never
 * logged (Plan §3 rule 14). Explains Magic Link sign-in after acceptance.
 */
final class StaffInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $rawToken,
        private readonly string $businessName,
        private readonly string $branchName,
        private readonly string $roleLabel,
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to join {$this->businessName} on Servana")
            ->greeting('Welcome to Servana')
            ->line("{$this->businessName} has invited you to join the {$this->branchName} branch as their {$this->roleLabel}.")
            ->line('Accept your invitation to set up your profile. Afterwards you sign in any time with a secure Magic Link — no password to remember.')
            ->action('Accept invitation', $this->acceptUrl())
            ->line('This invitation expires in 72 hours. If you were not expecting it, you can safely ignore this email.')
            ->salutation('— The Servana team');
    }

    /** SPA accept route: {frontend}/staff/accept?token=<raw>. */
    private function acceptUrl(): string
    {
        $base = rtrim((string) Config::get('servana.frontend_url', Config::get('app.url')), '/');

        return $base.'/staff/accept?token='.$this->rawToken;
    }
}
