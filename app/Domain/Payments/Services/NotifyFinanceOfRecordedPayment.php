<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Notifications\FinancePaymentRecordedNotification;
use App\Domain\Payments\ValueObjects\PaymentRecordingResult;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * The smallest safe Finance-notification seam (Plan §41, Gate D; Phase 18A).
 * Dispatches a queued, masked {@see FinancePaymentRecordedNotification} AFTER the
 * recording commits, to active Finance members of the same merchant whose branch
 * scope includes the group's branch (all hold customer_payment.view). Naturally
 * idempotent — the recording action runs once per idempotent request, so replay
 * does not re-notify. Sends nothing when there are no eligible recipients. No Phase
 * 21N durable notifications table is created.
 */
final class NotifyFinanceOfRecordedPayment
{
    public function dispatch(PaymentRecordingResult $result): void
    {
        $group = $result->group;
        $group->loadMissing(['invoice', 'records']);

        /** @var list<User> $recipients */
        $recipients = MerchantUser::query()
            ->active()
            ->where('merchant_id', $group->merchant_id)
            ->where('role', MerchantUserRole::Finance->value)
            ->with('user')
            ->get()
            ->filter(fn (MerchantUser $member): bool => in_array($group->branch_id, $member->activeBranchIds(), true))
            ->map(fn (MerchantUser $member): ?User => $member->user)
            ->filter()
            ->values()
            ->all();

        if ($recipients === []) {
            return;
        }

        /** @var list<string> $methods */
        $methods = array_values($group->records
            ->map(fn (PaymentRecord $record): string => $record->method->value)
            ->unique()
            ->all());

        Notification::send($recipients, new FinancePaymentRecordedNotification(
            $group->ulid,
            $group->invoice?->invoice_number,
            $result->held,
            $group->total_amount_minor,
            $group->currency,
            $methods,
        ));
    }
}
