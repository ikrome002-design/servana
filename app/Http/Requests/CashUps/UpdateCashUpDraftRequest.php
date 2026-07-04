<?php

declare(strict_types=1);

namespace App\Http\Requests\CashUps;

use App\Domain\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a cash-up draft PUT (Plan §45; Phase 18B). The Branch Manager submits
 * per-method counted amounts only; the expected amounts are server-derived and are
 * NOT accepted from the client. Methods must be concrete (never split_payment).
 * Authorization is enforced by the route permission + policy, not here.
 */
final class UpdateCashUpDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $concrete = array_map(
            static fn (PaymentMethod $m): string => $m->value,
            PaymentMethod::concreteMethods(),
        );

        return [
            'counts' => ['required', 'array', 'min:1'],
            'counts.*.method' => ['required', 'string', Rule::in($concrete)],
            'counts.*.counted_minor' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Counts collapsed to a method => counted_minor map (last write wins on dupes).
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        /** @var list<array{method: string, counted_minor: int}> $rows */
        $rows = $this->validated('counts');
        foreach ($rows as $row) {
            $counts[$row['method']] = (int) $row['counted_minor'];
        }

        return $counts;
    }
}
