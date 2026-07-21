<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mark an approved payout run PAID after an EXTERNAL settlement (Plan §62; §25.5; §H8). **Servana moves
 * no money** — this records that an external payment already happened. The caller supplies the external
 * payment reference (stored ENCRYPTED at rest, never logged/audited) and the paid date (an
 * Africa/Nairobi business date that cannot be in the future — a settlement cannot pre-date its
 * recording). `paid_at`/`status`/`paid_by` are server-owned. Authorization (`payout_run.mark_paid`),
 * fresh MFA step-up, and Idempotency-Key are enforced at the route.
 */
final class MarkPayoutRunPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'external_payment_reference' => ['required', 'string', 'min:3', 'max:255'],
            'paid_date' => ['required', 'date', 'before_or_equal:today'],
            // Server-owned fields are never accepted from the client.
            'status' => ['prohibited'],
            'paid_by' => ['prohibited'],
            'paid_at' => ['prohibited'],
            'external_payment_reference_encrypted' => ['prohibited'],
            'gross_total_minor' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('external_payment_reference'))) {
            $this->merge(['external_payment_reference' => trim($this->input('external_payment_reference'))]);
        }
    }
}
