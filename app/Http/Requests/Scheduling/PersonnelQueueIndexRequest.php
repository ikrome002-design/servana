<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Http\Api\ApiPagination;
use App\Http\Requests\Concerns\NormalizesQueryBooleans;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filters for a Personnel user's OWN queue (Plan §37, §19;
 * Phase 16B). No branch-wide filter and no staff selector — the own-scope
 * restriction is enforced server-side in the controller.
 */
final class PersonnelQueueIndexRequest extends FormRequest
{
    use NormalizesQueryBooleans;

    public const SORTS = ['position', '-position', 'queued_at', '-queued_at'];

    public function authorize(): bool
    {
        return true; // own-scope + personnel.my_queue.view enforced in the controller
    }

    /** `?active=true` is the contract's own serialisation of a query boolean (LUAT-D02). */
    protected function prepareForValidation(): void
    {
        $this->normalizeQueryBooleans(['active']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(array_column(QueueEntryStatus::cases(), 'value'))],
        ];
    }
}
