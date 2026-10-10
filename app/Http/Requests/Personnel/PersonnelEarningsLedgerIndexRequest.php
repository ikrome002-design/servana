<?php

declare(strict_types=1);

namespace App\Http\Requests\Personnel;

use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bounded filters shared by the own commission and salary ledger read endpoints. */
final class PersonnelEarningsLedgerIndexRequest extends FormRequest
{
    private const SORTS = [
        'created_at', '-created_at', 'earned_at', '-earned_at',
        'pay_period_start', '-pay_period_start', 'pay_period_end', '-pay_period_end',
    ];

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
            'status' => ['sometimes', 'string', Rule::in(array_values(array_unique([
                ...CommissionLedgerStatus::values(),
                ...SalaryLedgerStatus::values(),
            ])))],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
