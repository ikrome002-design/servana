<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Welcome email for an initial Branch / HR user added during first-time setup
 * (Scope §3.2 step 6).
 *
 * Phase 6 boundary: this is a SAFE notification only — it carries NO Magic Link
 * token and grants no access. The recipient's membership is `invited`; the full
 * invitation-accept flow (token → activation → branch assignment) is Phase 7.
 * The mail explains that they'll sign in with a Magic Link once their access is
 * activated, so the wording is correct across the Phase 6/7 seam.
 */
final class StaffWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $businessName,
        private readonly string $roleLabel,
        private readonly string $branchName,
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
        $loginUrl = rtrim((string) Config::get('servana.frontend_url', Config::get('app.url')), '/').'/auth/login';

        return (new MailMessage)
            ->subject("You've been added to {$this->businessName} on Servana")
            ->greeting('Welcome to Servana')
            ->line("{$this->businessName} has added you as their {$this->roleLabel} for the {$this->branchName} branch.")
            ->line('Servana uses passwordless sign-in. When your access is activated, you can sign in any time with a secure Magic Link sent to this email address — no password to remember.')
            ->action('Go to Servana sign-in', $loginUrl)
            ->line('If you were not expecting this, you can safely ignore this email.')
            ->salutation('— The Servana team');
    }
}
