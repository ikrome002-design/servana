<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a proposed feature-flag change (COR-UI08-001 section 12.3; Phase UI-08).
 *
 * THE FOUR GOVERNANCE FIELDS ARE MANDATORY, here and at the database. A production-sensitive change
 * with no stated impact, no rollback plan or no health criterion is not something to discourage; it
 * is something that must not be representable.
 *
 * There is NO `flag_key` field in the body: the key comes from the route and must already exist in
 * the code catalogue. Rollout is integer basis points, never a float percentage, and target types
 * are validated against the closed vocabulary before the action re-checks them against the
 * definition.
 */
final class RequestFeatureFlagChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'state' => ['required', Rule::in(PlatformFeatureFlagState::values())],
            'rollout_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'targets' => ['sometimes', 'array', 'max:500'],
            'targets.*.type' => ['required', Rule::in(PlatformFeatureFlagTargetType::values())],
            'targets.*.value' => ['required', 'string', 'max:64'],

            'impact_statement' => ['required', 'string', 'min:12', 'max:2000'],
            'rollback_plan' => ['required', 'string', 'min:12', 'max:2000'],
            'health_criterion' => ['required', 'string', 'min:12', 'max:2000'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ];
    }

    /**
     * @return array{state:string,rollout_basis_points:int,effective_from:string|null,effective_to:string|null,targets:list<array{type:string,value:string}>}
     */
    public function configuration(): array
    {
        /** @var list<array{type:string,value:string}> $targets */
        $targets = $this->validated('targets') ?? [];

        $effectiveFrom = $this->validated('effective_from');
        $effectiveTo = $this->validated('effective_to');

        return [
            'state' => (string) $this->validated('state'),
            'rollout_basis_points' => (int) $this->validated('rollout_basis_points'),
            'effective_from' => is_string($effectiveFrom) ? $effectiveFrom : null,
            'effective_to' => is_string($effectiveTo) ? $effectiveTo : null,
            'targets' => $targets,
        ];
    }

    /** @return array{impact_statement:string,rollback_plan:string,health_criterion:string,reason:string} */
    public function governance(): array
    {
        return [
            'impact_statement' => (string) $this->validated('impact_statement'),
            'rollback_plan' => (string) $this->validated('rollback_plan'),
            'health_criterion' => (string) $this->validated('health_criterion'),
            'reason' => (string) $this->validated('reason'),
        ];
    }
}
