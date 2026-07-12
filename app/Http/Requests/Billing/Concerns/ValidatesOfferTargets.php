<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing\Concerns;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Enums\PromotionTargetType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Shared validation for the normalized target rows of a promotion / free-period offer (Plan §53;
 * Gate C2; Phase 20C). Targets arrive as explicit rows referencing merchants/plans by ULID (external
 * ids) or a billing-mode value — never JSON blobs. The scope determines exactly which target type is
 * allowed and whether any targets are permitted; a friendly 422 is raised before the DB CHECKs fire.
 */
trait ValidatesOfferTargets
{
    /** @return array<string, mixed> */
    protected function targetRules(): array
    {
        return [
            'targets' => ['array'],
            'targets.*.target_type' => ['required_with:targets', Rule::in(PromotionTargetType::values())],
            'targets.*.merchant_id' => ['nullable', 'string', 'size:26', Rule::exists('merchants', 'ulid')],
            'targets.*.subscription_plan_id' => ['nullable', 'string', 'size:26', Rule::exists('subscription_plans', 'ulid')],
            'targets.*.billing_mode' => ['nullable', Rule::in(BillingMode::values())],
        ];
    }

    protected function validateTargetCoherence(Validator $validator): void
    {
        $scope = $this->input('target_scope');
        if (! is_string($scope)) {
            return;
        }

        /** @var array<int, array<string, mixed>> $targets */
        $targets = (array) $this->input('targets', []);

        if ($scope === PromotionTargetScope::AllNewMerchants->value) {
            if ($targets !== []) {
                $validator->errors()->add('targets', 'A global (all_new_merchants) offer must not have targets.');
            }

            return;
        }

        $expected = match ($scope) {
            PromotionTargetScope::SelectedMerchants->value => PromotionTargetType::Merchant->value,
            PromotionTargetScope::SelectedPlans->value => PromotionTargetType::Plan->value,
            PromotionTargetScope::BillingMode->value => PromotionTargetType::BillingMode->value,
            default => null,
        };
        if ($expected === null) {
            return;
        }

        if ($targets === []) {
            $validator->errors()->add('targets', 'This scope requires at least one target.');

            return;
        }

        $field = match ($expected) {
            PromotionTargetType::Merchant->value => 'merchant_id',
            PromotionTargetType::Plan->value => 'subscription_plan_id',
            default => 'billing_mode',
        };

        foreach ($targets as $index => $target) {
            if (($target['target_type'] ?? null) !== $expected) {
                $validator->errors()->add("targets.{$index}.target_type", "Target type must be {$expected} for this scope.");

                continue;
            }
            if (empty($target[$field])) {
                $validator->errors()->add("targets.{$index}.{$field}", "The {$field} is required for this target.");
            }
        }
    }
}
