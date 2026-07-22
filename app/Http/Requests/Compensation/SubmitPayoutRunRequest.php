<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Submit (freeze) an HR draft payout run (Plan §62; Phase 20H, §H7). Bodiless — the action re-snapshots
 * eligibility under lock and claims the ledgers; the browser supplies nothing. Any server-owned field
 * is rejected so a client can never smuggle a status/total into the freeze.
 */
final class SubmitPayoutRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['prohibited'],
            'gross_total_minor' => ['prohibited'],
            'items' => ['prohibited'],
            'payout_item_ids' => ['prohibited'],
        ];
    }
}
