<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Domain\Messaging\Sms\Support\ServedClientSelector;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validated pagination/search for a Personnel user's OWN served clients (Plan §23, §64, §68;
 * ADR-010; Phase 21S).
 *
 * NO personnel identifier is accepted — own scope is derived from the authenticated membership in
 * the controller. The `search` term is bounded and matched against the client NAME only by
 * {@see ServedClientSelector}; there is deliberately no phone or
 * email search parameter, because either would turn this endpoint into an oracle that confirms
 * whether a guessed contact belongs to a client.
 */
final class ServedClientsSmsIndexRequest extends FormRequest
{
    /** Allowlisted sorts. Contact columns are absent by design (ADR-010). */
    public const SORTS = ['full_name', '-full_name', 'created_at', '-created_at'];

    public function authorize(): bool
    {
        return true; // own-scope + personnel.my_served_clients.view enforced by middleware + controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'search' => ['sometimes', 'string', 'max:80'],
        ];
    }
}
