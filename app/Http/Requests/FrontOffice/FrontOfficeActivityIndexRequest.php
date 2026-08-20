<?php

declare(strict_types=1);

namespace App\Http\Requests\FrontOffice;

use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FrontOfficeActivityIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at'];

    public const DOMAINS = ['clients', 'appointments', 'queue', 'sessions', 'invoices', 'billing'];

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
            'domain' => ['sometimes', 'string', Rule::in(self::DOMAINS)],
        ];
    }
}
