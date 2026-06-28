<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Domain\Clients\Enums\ClientStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/search for the client list (Plan §23, §35). `q` searches
 * by client name OR normalized phone number (resolved to a blind index in the
 * controller); search is branch- and tenant-scoped and returns masked contact
 * only. Sorts are allowlisted (never on a contact column).
 */
final class ClientIndexRequest extends FormRequest
{
    public const SORTS = ['full_name', '-full_name', 'created_at', '-created_at'];

    public function authorize(): bool
    {
        return true; // ClientPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'q' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'string', Rule::in(array_column(ClientStatus::cases(), 'value'))],
        ];
    }
}
