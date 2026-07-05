<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Flagged-event resolve/dismiss body (Plan §25; Phase 19). A review note is mandatory
 * for both resolve and dismiss (mirrors the DB resolution CHECK).
 */
final class FlaggedEventResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'review_notes' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
