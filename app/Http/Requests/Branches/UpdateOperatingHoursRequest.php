<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a weekly operating-hours upsert (Scope §3.3 Branch Operating
 * Calendar). Expects one entry per weekday (0–6).
 */
final class UpdateOperatingHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'array', 'min:1', 'max:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.is_closed' => ['required', 'boolean'],
            'hours.*.opens_at' => ['nullable', 'required_if:hours.*.is_closed,false', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'required_if:hours.*.is_closed,false', 'date_format:H:i', 'after:hours.*.opens_at'],
            'hours.*.break_start' => ['nullable', 'date_format:H:i'],
            'hours.*.break_end' => ['nullable', 'date_format:H:i', 'after:hours.*.break_start'],
        ];
    }
}
