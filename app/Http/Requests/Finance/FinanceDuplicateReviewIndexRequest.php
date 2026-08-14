<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FinanceDuplicateReviewIndexRequest extends FormRequest
{
    public const SORTS = ['checked_at', '-checked_at'];

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
            'method' => ['sometimes', 'string', Rule::enum(PaymentMethod::class)],
        ];
    }
}
