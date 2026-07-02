<?php

declare(strict_types=1);

namespace App\Domain\Payments\Notifications;

use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Finance notification that a merchant-client payment recording group awaits action
 * (Plan §41, Gate D; Phase 18A). Queued (mail), sent AFTER the recording commits, to
 * active Finance members of the same merchant + branch (all hold customer_payment
 * .view). Carries ONLY safe data — group ULID, invoice number, component method
 * labels, integer amount + currency — never a full/normalized reference or client
 * contact. The Phase 21N durable in-app notifications platform is a later phase.
 */
final class FinancePaymentRecordedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $methods */
    public function __construct(
        private readonly string $groupUlid,
        private readonly ?string $invoiceNumber,
        private readonly bool $duplicateReview,
        private readonly int $amountMinor,
        private readonly string $currency,
        private readonly array $methods,
    ) {
        $this->onQueue('mail');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = Money::ofMinor($this->amountMinor, Currency::from($this->currency))->format();
        $methods = implode(', ', $this->methods);
        $reference = $this->invoiceNumber ?? $this->groupUlid;

        $message = (new MailMessage)
            ->subject($this->duplicateReview
                ? "Duplicate payment reference needs review — invoice {$reference}"
                : "Payment recorded, pending validation — invoice {$reference}")
            ->greeting('Servana Finance');

        if ($this->duplicateReview) {
            $message->line("A recorded payment of {$amount} ({$methods}) on invoice {$reference} matched an existing reference and is held for your review.")
                ->line('Only an authorized Finance override (with a reason and a fresh step-up) can release it.');
        } else {
            $message->line("A payment of {$amount} ({$methods}) has been recorded against invoice {$reference} and is pending your validation.")
                ->line('Validating the recording group is the next step — no receipt exists until you do.');
        }

        return $message
            ->action('Review in Servana', $this->financeUrl())
            ->line('This message contains no payment reference or client contact details.')
            ->salutation('— Servana');
    }

    private function financeUrl(): string
    {
        $base = rtrim((string) Config::get('servana.frontend_url', Config::get('app.url')), '/');

        return $base.'/finance/payment-recording-groups/'.$this->groupUlid;
    }
}
