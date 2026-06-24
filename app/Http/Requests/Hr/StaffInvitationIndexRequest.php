<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filter/sort for the staff-invitation listing (Plan §23,
 * §24.2). Sorts, status and role filters are allowlisted; pagination is bounded.
 */
final class StaffInvitationIndexRequest extends FormRequest
{
    /** Allowlisted sort tokens (indexed columns; `-` = descending). */
    public const SORTS = ['created_at', '-created_at'];

    public function authorize(): bool
    {
        return true; // route middleware + StaffInvitationPolicy are the authorization boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'status' => ['sometimes', 'string', Rule::in(array_column(StaffInvitationStatus::cases(), 'value'))],
            'role' => ['sometimes', 'string', Rule::in(array_column(MerchantUserRole::cases(), 'value'))],
        ];
    }
}
