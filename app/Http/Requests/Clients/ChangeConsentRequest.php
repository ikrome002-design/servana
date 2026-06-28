<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Domain\Clients\Enums\ConsentState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SMS-consent change validation (Plan §35). Channel is implicitly `sms` (the only
 * channel in Phase 15A); only the state is supplied.
 */
final class ChangeConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', Rule::in(array_column(ConsentState::cases(), 'value'))],
        ];
    }
}
