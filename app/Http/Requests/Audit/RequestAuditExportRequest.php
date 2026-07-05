<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Domain\Audit\Enums\AuditDomain;
use App\Domain\Audit\Enums\AuditSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate an Audit export request (Plan §13.5, §19.3, §80; Phase 19; ADR-010).
 *
 * A non-empty reason and exactly one branch (ULID) are mandatory; the filter snapshot
 * (date range, event domains, severities) is allowlisted. Route middleware enforces
 * `audit.export` + fresh step-up + branch scope; this only shapes the validated body.
 */
final class RequestAuditExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware + policy are the authorization boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'domains' => ['sometimes', 'array'],
            'domains.*' => ['string', Rule::in(array_map(static fn (AuditDomain $d): string => $d->value, AuditDomain::cases()))],
            'severities' => ['sometimes', 'array'],
            'severities.*' => ['string', Rule::in(array_map(static fn (AuditSeverity $s): string => $s->value, AuditSeverity::cases()))],
        ];
    }

    /**
     * The validated filter snapshot persisted to `audit_exports.scope_json` (allowlisted
     * keys only — never raw request input). Referenced here so a future action reuses it.
     *
     * @return array<string, mixed>
     */
    public function scopeSnapshot(): array
    {
        $validated = $this->validated();

        $snapshot = [
            'domains' => $validated['domains'] ?? [AuditDomain::General->value],
            'severities' => $validated['severities'] ?? [],
        ];
        if (isset($validated['date_from'])) {
            $snapshot['date_from'] = $validated['date_from'];
        }
        if (isset($validated['date_to'])) {
            $snapshot['date_to'] = $validated['date_to'];
        }

        return $snapshot;
    }
}
