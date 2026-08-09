<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a cost-notice preview (COR-UI08-001 §9; Phase UI-08).
 *
 * The notice is computed SERVER-SIDE from the effective rule; the client supplies only the shape of
 * the hypothetical campaign. Bounds are deliberate: an unbounded recipient or segment count would
 * let a caller drive an arbitrarily large multiplication purely to probe overflow behaviour.
 */
final class SmsBillingCostNoticePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipient_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'segment_count' => ['required', 'integer', 'min:0', 'max:100'],
            'as_of' => ['nullable', 'date'],
        ];
    }
}
