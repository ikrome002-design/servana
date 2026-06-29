<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filters for the Personnel own-appointments list (Plan §36,
 * §19.3). Personnel see ONLY appointments assigned to their own staff profile; the
 * own-scope restriction is enforced server-side in the controller, never by a
 * client-supplied personnel filter (there is none here).
 */
final class PersonnelAppointmentIndexRequest extends FormRequest
{
    public const SORTS = ['starts_at', '-starts_at'];

    public function authorize(): bool
    {
        return true; // own-scope + permission enforced in the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['sometimes', 'string', Rule::in(array_column(AppointmentStatus::cases(), 'value'))],
        ];
    }
}
