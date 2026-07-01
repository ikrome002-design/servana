<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoiceStateException;

/**
 * Merchant-Client Invoice state-machine guard (Plan §25.1/§25.3; Phase 17).
 *
 * THE single place that authorizes an invoice status transition. Domain actions
 * call {@see ensure()} before writing; the transition inventory lives on
 * {@see InvoiceStatus::allowedTransitions()}. There is no generic `PATCH status`,
 * `mark-paid`, or `mark-void` — every transition has a named action and runs
 * through here.
 */
final class InvoiceStateMachine
{
    public function canTransition(InvoiceStatus $from, InvoiceStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * Assert a transition is legal or throw the canonical 422 envelope.
     *
     * @throws InvoiceStateException
     */
    public function ensure(InvoiceStatus $from, InvoiceStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw InvoiceStateException::invalidTransition($from, $to);
        }
    }
}
