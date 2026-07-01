<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filters for the invoice list (Plan §23, §40; Phase 17).
 * Filters are indexed columns; sorts are allowlisted. Reads are branch-scoped by the
 * model. Phase 22 cross-domain search is out of scope.
 */
final class InvoiceIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at', 'finalized_at', '-finalized_at'];

    public function authorize(): bool
    {
        return true; // InvoicePolicy + EnsurePermission are the boundary
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
