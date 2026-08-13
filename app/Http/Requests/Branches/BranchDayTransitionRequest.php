<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use Illuminate\Foundation\Http\FormRequest;

/** Bodyless Branch Day transition request; branch and actor are server-derived. */
final class BranchDayTransitionRequest extends FormRequest
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
