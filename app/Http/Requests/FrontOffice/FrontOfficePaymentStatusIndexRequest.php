<?php

declare(strict_types=1);

namespace App\Http\Requests\FrontOffice;

use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FrontOfficePaymentStatusIndexRequest extends FormRequest
{
    public const SORTS = ['recorded_at', '-recorded_at', 'created_at', '-created_at'];

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
            'status' => ['sometimes', 'string', Rule::enum(PaymentRecordingGroupStatus::class)],
        ];
    }
}
