<?php

declare(strict_types=1);

namespace App\Http\Requests\Merchant;

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validated, bounded filters for the Merchant Administrator's safe lifecycle directory. */
final class MerchantStaffOverviewIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at', 'activated_at', '-activated_at'];

    public function authorize(): bool
    {
        return true; // EnsurePermission is the server-side collection authority.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'search' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', 'string', Rule::in(array_column(MerchantUserRole::cases(), 'value'))],
            'status' => ['sometimes', 'string', Rule::in(array_column(MerchantUserStatus::cases(), 'value'))],
            'branch_ulid' => ['sometimes', 'string', 'size:26'],
        ];
    }
}
