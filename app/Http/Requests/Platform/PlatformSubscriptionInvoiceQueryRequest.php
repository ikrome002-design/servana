<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Merchants\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the platform subscription-invoice query (COR-UI08-001 §10; Phase UI-08).
 *
 * Same contract as the subscription query: canonical enum for status, public ULIDs only, an
 * allowlisted sort vocabulary, and a bounded page size.
 */
final class PlatformSubscriptionInvoiceQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(array_map(static fn (SubscriptionInvoiceStatus $c): string => $c->value, SubscriptionInvoiceStatus::cases()))],
            'merchant' => ['nullable', 'string', 'size:26'],
            'issued_from' => ['nullable', 'date'],
            'issued_to' => ['nullable', 'date', 'after_or_equal:issued_from'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
            'sort' => ['nullable', Rule::in(['issued_at', 'due_at', 'total_minor', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $merchantUlid = $this->validated('merchant');
        $merchantId = null;

        if (is_string($merchantUlid) && $merchantUlid !== '') {
            $id = Merchant::query()->where('ulid', $merchantUlid)->value('id');
            $merchantId = $id === null ? -1 : (int) $id;
        }

        return [
            'status' => $this->validated('status'),
            'merchant_id' => $merchantId,
            'issued_from' => $this->instant('issued_from'),
            'issued_to' => $this->instant('issued_to'),
            'due_from' => $this->instant('due_from'),
            'due_to' => $this->instant('due_to'),
            'sort' => $this->validated('sort'),
            'direction' => $this->validated('direction'),
        ];
    }

    private function instant(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
