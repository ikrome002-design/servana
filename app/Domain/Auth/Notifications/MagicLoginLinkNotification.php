<?php

declare(strict_types=1);

namespace App\Domain\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Branded Magic Link sign-in email (Plan §9.1, §20 mail queue, brand voice).
 *
 * The raw token lives only on this notification instance and the rendered link;
 * it is never logged. The body carries no account data beyond the action itself.
 */
final class MagicLoginLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $rawToken)
    {
        // Magic Links take mail-queue priority (Plan §20).
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
            ->subject('Your Servana sign-in link')
            ->greeting('Sign in to Servana')
            ->line('Use the button below to sign in. For your security, this link works once and expires in 15 minutes.')
            ->action('Sign in to Servana', $this->verifyUrl())
            ->line('If you did not request this, you can safely ignore this email — no one can sign in without the link.')
            ->salutation('— The Servana team');
    }

    /** SPA verify route (Plan §9.1): {frontend}/auth/verify?token=<raw>. */
    private function verifyUrl(): string
    {
        $base = rtrim((string) Config::get('servana.frontend_url', Config::get('app.url')), '/');

        return $base.'/auth/verify?token='.$this->rawToken;
    }
}
