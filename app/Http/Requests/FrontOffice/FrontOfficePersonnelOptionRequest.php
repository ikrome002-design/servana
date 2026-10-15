<?php

declare(strict_types=1);

namespace App\Http\Requests\FrontOffice;

use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LOCAL-UAT-001, LUAT-D01 — which Front Office workflow an eligible-personnel list feeds.
 *
 * Each purpose maps, SERVER-SIDE and fixed, to the existing permission of that one workflow, so
 * holding one workflow's key never opens another workflow's choices. An unknown purpose is a 422; a
 * known purpose without its key is a 403 (default deny).
 */
final class FrontOfficePersonnelOptionRequest extends FormRequest
{
    public const PURPOSES = [
        'walk_in' => 'queue.create',
        'appointment' => 'appointment.create',
        'appointment_assign' => 'appointment.assign',
        'appointment_transfer' => 'appointment.transfer',
        'queue_assign' => 'queue.assign',
        'queue_transfer' => 'queue.transfer',
    ];

    public function authorize(): bool
    {
        return true; // the purpose's own permission is checked by the controller after validation
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ApiPagination::rules() + [
            'page' => ['sometimes', 'integer', 'min:1'],
            'purpose' => ['required', 'string', Rule::in(array_keys(self::PURPOSES))],
        ];
    }

    /** The existing permission the validated purpose requires. */
    public function requiredPermission(): string
    {
        return self::PURPOSES[(string) $this->validated('purpose')];
    }
}
