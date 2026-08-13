<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for Branch Manager's narrow, read-only invoice visibility projection. */
final class BranchInvoiceVisibilityIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at', 'finalized_at', '-finalized_at'];

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
            'status' => ['sometimes', 'string', Rule::in(array_column(InvoiceStatus::cases(), 'value'))],
        ];
    }
}
