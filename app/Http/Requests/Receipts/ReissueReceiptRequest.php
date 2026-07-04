<?php

declare(strict_types=1);

namespace App\Http\Requests\Receipts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Receipt reissue body (Plan §43; Phase 18B). A non-empty reason is mandatory. The
 * actor is the authenticated Finance user (server-derived); the target is the
 * route-bound {receipt}. Permission (receipt.reissue) + branch scope + idempotency are
 * the route-level boundary.
 */
final class ReissueReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ReceiptPolicy::reissue + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:480'],
        ];
    }
}
