<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FinancePartialSplitIndexRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::enum(InvoiceStatus::class)],
        ];
    }
}
