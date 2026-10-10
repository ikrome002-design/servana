<?php

declare(strict_types=1);

namespace App\Http\Requests\Personnel;

use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;

/** Name-only search and bounded pagination for the own served-client context page. */
final class PersonnelServedClientIndexRequest extends FormRequest
{
    private const SORTS = ['full_name', '-full_name', 'last_served_at', '-last_served_at'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
