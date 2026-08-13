<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use Illuminate\Foundation\Http\FormRequest;

/** Validated, bodyless request for the assigned-branch operational overview. */
final class BranchDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
