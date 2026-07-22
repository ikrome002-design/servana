<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Actions;

use App\Domain\Billing\Actions\CreateTrialSubscription;
use App\Domain\Billing\Services\ResolveSetupPlanPrice;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Integrations\ReferEarn\Actions\EnqueueProductEvent;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantStatusHistory;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Onboarding\Data\FirstTimeSetupData;
use App\Domain\Onboarding\Notifications\StaffWelcomeNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Complete first-time merchant setup (Scope §3.2 steps 1–7, Plan §27 Phase 6).
 *
 * Transactional. Enforces, server-side, every setup step:
 *   1 service fee tier        → persisted on the merchant (required)
 *   2 merchant profile        → category + contact details persisted
 *   3 ≥1 branch               → branch created (Phase 6 minimal entity)
 *   4 initial Branch + HR      → invited memberships created (status=invited)
 *   5 branch of operation     → auto-selected (single branch) onto each member
 *   6 welcome emails          → safe StaffWelcomeNotification (no token; Ph.7 accept)
 *   7 status → active          → merchant flipped to active + setup_completed_at
 *
 * Returns the refreshed, now-active merchant so the caller can signal the SPA to
 * redirect to the dashboard (step 7).
 */
final class CompleteFirstTimeSetup
{
    public function __construct(
        private readonly CreateTrialSubscription $createTrialSubscription,
        private readonly ResolveSetupPlanPrice $resolveSetupPlanPrice,
        private readonly EnqueueProductEvent $enqueueProductEvent,
    ) {}

    public function handle(Merchant $merchant, User $actor, FirstTimeSetupData $data): Merchant
    {
        return DB::transaction(function () use ($merchant, $actor, $data): Merchant {
            // Phase 20B — resolve + validate the selected plan/price BEFORE any mutation, so an
            // invalid selection rolls the whole completion back (422; no partial setup).
            $price = $this->resolveSetupPlanPrice->resolve(
                $data->subscriptionPlanUlid,
                $data->subscriptionPlanPriceUlid,
            );

            // Step 1 — service fee tier (required before completion). Distinct from the subscription
            // plan: service_fee_tier is the percentage-fee tier behavior (§51), not the plan.
            $merchant->service_fee_tier = $data->serviceFeeTier;

            // Step 2 — merchant profile.
            $profile = $merchant->profile()->firstOrCreate(['merchant_id' => $merchant->id]);
            $profile->fill([
                'business_category' => $data->businessCategory,
                'contact_phone' => $data->contactPhone,
                'contact_email' => $data->contactEmail ?? $profile->contact_email,
                'receipt_display_name' => $data->receiptDisplayName ?? $merchant->name,
                'address' => $data->address,
                'town' => $data->town,
                'timezone' => $data->timezone,
            ]);
            $profile->save();

            // Step 3 — at least one branch (Phase 6 minimal branch entity).
            $branch = MerchantBranch::query()->create([
                'merchant_id' => $merchant->id,
                'name' => $data->branchName,
                'code' => $data->branchCode,
                'town' => $data->branchTown,
                'address' => $data->branchAddress,
                'phone' => $data->branchPhone,
                'email' => $data->branchEmail,
                'business_category' => $data->businessCategory,
                'created_by' => $actor->id,
            ]);

            // Steps 4–6 — invite the initial Branch Manager + HR, auto-select the
            // single branch (step 5), and send a safe welcome email (step 6).
            $this->inviteStaff($merchant, $actor, $branch, $data->branchManagerEmail, MerchantUserRole::BranchManager, 'Branch Manager');
            $this->inviteStaff($merchant, $actor, $branch, $data->hrEmail, MerchantUserRole::Hr, 'Human Resource manager');

            // Step 7 — flip to active.
            $merchant->status = MerchantStatus::Active;
            $merchant->setup_completed_at = now();
            $merchant->save();

            MerchantStatusHistory::query()->create([
                'merchant_id' => $merchant->id,
                'from_status' => MerchantStatus::PendingSetup->value,
                'to_status' => MerchantStatus::Active->value,
                'reason' => 'first_time_setup_completed',
                'changed_by' => $actor->id,
            ]);

            // Phase 20B — bind the trial subscription as part of completed onboarding (Gate B1).
            // Idempotent (an existing current subscription short-circuits); anchored to the founding
            // Merchant-Administrator membership; projects merchants.billing_status → trialing. A
            // failure here rolls back the whole completion (no merchant marked set-up without a
            // subscription).
            $this->createTrialSubscription->handle($merchant, $price, $actor);

            $merchant = $merchant->refresh();

            // Phase 21R-A (Plan §58B.1, §58A.2). Same transaction as the status flip, so the fact
            // and its event are inseparable. The emission-scope gate lives inside the action: an
            // unreferred merchant, a malformed code and a rejected claim all emit nothing.
            $this->enqueueProductEvent->handle(ReOutboundEventType::MerchantSetupCompleted, $merchant);

            return $merchant;
        });
    }

    /**
     * Create (or reuse) the staff user and an `invited` membership bound to the
     * chosen branch, then send the welcome email. Idempotent within the merchant:
     * an existing membership for the same user is left untouched (no duplicate
     * row, no second email).
     */
    private function inviteStaff(
        Merchant $merchant,
        User $actor,
        MerchantBranch $branch,
        string $email,
        MerchantUserRole $role,
        string $roleLabel,
    ): void {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $email, 'status' => User::STATUS_ACTIVE],
        );

        $existing = MerchantUser::query()
            ->where('merchant_id', $merchant->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            return;
        }

        MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MerchantUserStatus::Invited,
            'invited_by' => $actor->id,
            // Step 5 — branch of operation auto-selected (single branch).
            'last_branch_id' => $branch->id,
        ]);

        $user->notify(new StaffWelcomeNotification($merchant->name, $roleLabel, $branch->name));
    }
}
