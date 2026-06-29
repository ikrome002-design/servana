<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\ValueObjects\TimeInterval;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * HR-only emergency-unavailability payload (Plan §80 Phase 15B). Creates a
 * date-specific UNAVAILABLE exception interval; HR authority is route-gated
 * (`personnel.availability.manage`) + policy-checked. A non-empty change reason is
 * mandatory. merchant_id / branch_id are never accepted from the body.
 */
final class EmergencyUnavailableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s'],
            'change_reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                TimeInterval::fromStrings((string) $this->input('start_time'), (string) $this->input('end_time'));
            } catch (InvalidArgumentException $e) {
                $validator->errors()->add('end_time', $e->getMessage());
            }
        });
    }
}
