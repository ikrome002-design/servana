<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Merchants\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the platform subscription query (COR-UI08-001 §10; Phase UI-08).
 *
 * Status and interval are validated against the canonical enums, so a caller cannot filter on a
 * value the state machine cannot produce. `merchant` and `plan` are public ULIDs; internal ids are
 * neither accepted nor exposed. `sort` is validated here and re-checked against an allowlist in the
 * projection — the request never reaches a column name.
 */
final class PlatformSubscriptionQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(array_map(static fn (MerchantSubscriptionStatus $c): string => $c->value, MerchantSubscriptionStatus::cases()))],
            'billing_interval' => ['nullable', Rule::in(array_map(static fn (BillingInterval $c): string => $c->value, BillingInterval::cases()))],
            'merchant' => ['nullable', 'string', 'size:26'],
            'plan' => ['nullable', 'string', 'size:26'],
            'renewal_from' => ['nullable', 'date'],
            'renewal_to' => ['nullable', 'date', 'after_or_equal:renewal_from'],
            'trial_ends_from' => ['nullable', 'date'],
            'trial_ends_to' => ['nullable', 'date', 'after_or_equal:trial_ends_from'],
            'has_scheduled_plan_change' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['current_period_end', 'trial_ends_at', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Translate public ULIDs to internal ids for the projection. An unknown ULID becomes -1 so the
     * query narrows to nothing instead of erroring, which keeps the filter non-enumerating.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'status' => $this->validated('status'),
            'billing_interval' => $this->validated('billing_interval'),
            'merchant_id' => $this->resolveId(Merchant::class, $this->validated('merchant')),
            'plan_id' => $this->resolveId(SubscriptionPlan::class, $this->validated('plan')),
            'renewal_from' => $this->instant('renewal_from'),
            'renewal_to' => $this->instant('renewal_to'),
            'trial_ends_from' => $this->instant('trial_ends_from'),
            'trial_ends_to' => $this->instant('trial_ends_to'),
            'has_scheduled_plan_change' => $this->boolean('has_scheduled_plan_change') ?: null,
            'sort' => $this->validated('sort'),
            'direction' => $this->validated('direction'),
        ];
    }

    /** @param  class-string<Model>  $model */
    private function resolveId(string $model, mixed $ulid): ?int
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $id = $model::query()->where('ulid', $ulid)->value('id');

        return $id === null ? -1 : (int) $id;
    }

    private function instant(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
