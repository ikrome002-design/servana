<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filters for a Personnel user's OWN SMS campaigns (Plan §23, §64; Phase 21S).
 *
 * No personnel identifier is accepted — own scope is derived from the authenticated membership in
 * the controller. Sorts and the status filter are allowlisted; there is no client, phone or
 * recipient filter, because filtering campaigns by who is in them would be a contact query
 * (ADR-010).
 */
final class PersonnelSmsCampaignIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at', 'confirmed_at', '-confirmed_at'];

    public function authorize(): bool
    {
        return true; // own-scope + permission enforced by middleware + controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'status' => ['sometimes', 'string', Rule::in(PersonnelSmsCampaignStatus::values())],
        ];
    }
}
