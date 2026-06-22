<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated, allowlisted filters for the audit-log read API (Plan §11, §70).
 *
 * Every filter is explicitly allowlisted and validated; sorts are restricted to
 * a fixed set; pagination is bounded. Unknown query params are ignored. Shared by
 * the merchant and platform audit endpoints (authorization differs by route).
 */
final class AuditLogIndexRequest extends FormRequest
{
    /** Allowlisted sortable expressions (column with optional `-` desc prefix). */
    public const SORTS = ['created_at', '-created_at', 'severity', '-severity', 'action', '-action'];

    /** Allowlisted subject types (model basenames). */
    public const SUBJECT_TYPES = [
        'MerchantBranch', 'MerchantUser', 'StaffInvitation', 'StaffProfile',
        'BranchDayRecord', 'BranchUserAssignment', 'MerchantUserPermissionOverride',
    ];

    public function authorize(): bool
    {
        return true; // route middleware + policy are the authorization boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'string', Rule::in(array_column(AuditEvent::cases(), 'value'))],
            'severity' => ['sometimes', 'string', Rule::in(array_column(AuditSeverity::cases(), 'value'))],
            'actor' => ['sometimes', 'string', 'size:26'],   // user ULID
            'branch' => ['sometimes', 'string', 'size:26'],  // branch ULID
            'subject_type' => ['sometimes', 'string', Rule::in(self::SUBJECT_TYPES)],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
