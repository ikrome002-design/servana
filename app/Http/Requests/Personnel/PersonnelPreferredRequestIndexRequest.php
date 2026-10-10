<?php

declare(strict_types=1);

namespace App\Http\Requests\Personnel;

use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for preferred-personnel requests naming the authenticated Personnel member. */
final class PersonnelPreferredRequestIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            'source' => ['sometimes', 'string', Rule::in(['queue', 'appointment'])],
            'status' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
