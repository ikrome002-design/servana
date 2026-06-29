<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\Support\ScheduleNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Atomic availability-replacement payload (Plan §80 Phase 15B). HR authority is
 * route-gated (`personnel.availability.manage`) + policy-checked. The body carries
 * the COMPLETE new recurring + exception schedule and a required human-readable
 * change reason. merchant_id / branch_id are NEVER accepted from the body — they
 * are derived from the staff profile in the domain action. Structural + overlap
 * validation is delegated to the shared ScheduleNormalizer so the API and the
 * action share one rule set.
 */
final class UpdateAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recurring' => ['present', 'array'],
            'recurring.*.weekday' => ['required', 'integer', 'between:0,6'],
            'recurring.*.start_time' => ['required', 'date_format:H:i,H:i:s'],
            'recurring.*.end_time' => ['required', 'date_format:H:i,H:i:s'],
            'recurring.*.available' => ['required', 'boolean'],

            'exceptions' => ['present', 'array'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d'],
            'exceptions.*.start_time' => ['required', 'date_format:H:i,H:i:s'],
            'exceptions.*.end_time' => ['required', 'date_format:H:i,H:i:s'],
            'exceptions.*.available' => ['required', 'boolean'],

            'change_reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return; // shape errors first — normalizer assumes well-formed rows
            }

            try {
                app(ScheduleNormalizer::class)->normalize(
                    $this->input('recurring', []),
                    $this->input('exceptions', []),
                );
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    foreach ((array) $messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }
}
