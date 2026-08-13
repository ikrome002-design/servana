<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Audit\Enums\AuditSeverity;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validated filters for HR's narrow, branch-scoped operational event timeline. */
final class HrAuditActivityIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at'];

    public const DOMAINS = ['staff', 'readiness', 'compensation', 'payout'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'severity' => ['sometimes', 'string', Rule::in(array_column(AuditSeverity::cases(), 'value'))],
            'domain' => ['sometimes', 'string', Rule::in(self::DOMAINS)],
            'action' => ['sometimes', 'string', 'max:160'],
        ];
    }
}
