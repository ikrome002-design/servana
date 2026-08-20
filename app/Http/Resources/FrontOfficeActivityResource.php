<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Narrow operational timeline row. It deliberately excludes raw audit context,
 * actor identity, IP, correlation id and hash-chain fields.
 *
 * @mixin AuditLog
 */
final class FrontOfficeActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'domain' => self::domainFor($this->action),
            'action' => $this->action,
            'label' => self::labelFor($this->action),
            'occurred_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function domainFor(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'client.') || str_starts_with($action, 'client_consent.') => 'clients',
            str_starts_with($action, 'appointment.') => 'appointments',
            str_starts_with($action, 'walk_in.') || str_starts_with($action, 'queue_entry.') => 'queue',
            str_starts_with($action, 'service_session.') => 'sessions',
            str_starts_with($action, 'invoice.') => 'invoices',
            default => 'billing',
        };
    }

    private static function labelFor(string $action): string
    {
        return match ($action) {
            'client.created' => 'Client record created',
            'client.updated' => 'Client record updated',
            'client_consent.opted_in' => 'Client SMS consent recorded',
            'client_consent.opted_out' => 'Client SMS consent withdrawn',
            'appointment.created' => 'Appointment created',
            'appointment.assigned' => 'Appointment assigned',
            'appointment.transferred' => 'Appointment transferred',
            'appointment.rescheduled' => 'Appointment rescheduled',
            'appointment.checked_in' => 'Client checked in',
            'appointment.cancelled' => 'Appointment cancelled',
            'appointment.no_show' => 'Appointment marked no-show',
            'appointment.queued' => 'Appointment moved to queue',
            'walk_in.created' => 'Walk-in arrival created',
            'queue_entry.created' => 'Queue entry created',
            'queue_entry.assigned' => 'Queue entry assigned',
            'queue_entry.called' => 'Client called from queue',
            'queue_entry.started' => 'Service started from queue',
            'queue_entry.completed' => 'Queue service completed',
            'queue_entry.transferred' => 'Queue entry transferred',
            'queue_entry.cancelled' => 'Queue entry cancelled',
            'queue_entry.no_show' => 'Queue entry marked no-show',
            'service_session.started' => 'Service session started',
            'service_session.completed' => 'Service session completed',
            'service_session.cancelled' => 'Service session cancelled',
            'invoice.created' => 'Invoice draft created',
            'invoice.updated_draft' => 'Invoice draft updated',
            'invoice.finalized' => 'Invoice finalized',
            'customer_payment.recorded' => 'Payment recorded for Finance validation',
            'customer_payment.duplicate_suspected' => 'Payment held for Finance review',
            'customer_payment.validated' => 'Finance validated a payment',
            'customer_payment.rejected' => 'Finance rejected a payment',
            'customer_payment.correction_requested' => 'Finance requested payment correction',
            'customer_payment.resubmitted' => 'Payment resubmitted to Finance',
            'receipt.issued' => 'Receipt issued automatically',
            default => 'Branch work updated',
        };
    }
}
