<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Exceptions;

use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus;
use App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid Personnel-SMS state transition (Plan §25.1, §64, guardrail §6.7; Phase 21S).
 *
 * Renders the Plan §11.5 envelope with the canonical `invalid_state_transition` code (422). Every
 * status change goes through a named domain action and
 * {@see PersonnelSmsCampaignStateMachine} / the recipient + billing machines; an unlisted
 * transition is rejected here, never by a silent no-op. Messages are generic and carry no internal
 * id, no client identity and — per ADR-010 — no contact of any kind.
 */
final class PersonnelSmsStateException extends Exception
{
    public static function invalidCampaignTransition(PersonnelSmsCampaignStatus $from, PersonnelSmsCampaignStatus $to): self
    {
        return new self("An SMS campaign cannot move from {$from->value} to {$to->value}.");
    }

    public static function invalidRecipientTransition(PersonnelSmsRecipientDeliveryStatus $from, PersonnelSmsRecipientDeliveryStatus $to): self
    {
        return new self("An SMS recipient cannot move from {$from->value} to {$to->value}.");
    }

    public static function invalidBillingTransition(SmsBillingEntryStatus $from, SmsBillingEntryStatus $to): self
    {
        return new self("An SMS billing entry cannot move from {$from->value} to {$to->value}.");
    }

    public static function notEditable(PersonnelSmsCampaignStatus $status): self
    {
        return new self("An SMS campaign can only be edited while it is a draft; this one is {$status->value}.");
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'invalid_state_transition',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
