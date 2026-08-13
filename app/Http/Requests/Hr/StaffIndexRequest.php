<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Enums\StaffEmploymentStatus;
use App\Domain\Hr\Enums\StaffEmploymentType;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filter/sort for the staff roster (Plan §23, §24.2).
 * Sorts and employment filters are allowlisted; pagination is bounded.
 */
final class StaffIndexRequest extends FormRequest
{
    /** Allowlisted sort tokens (indexed columns; `-` = descending). */
    public const SORTS = ['display_name', '-display_name', 'created_at', '-created_at'];

    public function authorize(): bool
    {
        return true; // route middleware + StaffProfilePolicy are the authorization boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'search' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', 'string', Rule::in(array_column(MerchantUserRole::cases(), 'value'))],
            'status' => ['sometimes', 'string', Rule::in(array_column(MerchantUserStatus::cases(), 'value'))],
            'employment_status' => ['sometimes', 'string', Rule::in(array_column(StaffEmploymentStatus::cases(), 'value'))],
            'employment_type' => ['sometimes', 'string', Rule::in(array_column(StaffEmploymentType::cases(), 'value'))],
        ];
    }
}
