<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Services;

use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Branches\Enums\BranchStatus;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Services\MerchantCompensationSummaryReadModel;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\TenantContext;

/**
 * Read-only Merchant Administrator home projection (UI/UX plan §6.1/§6.4.2).
 *
 * Every tenant-owned query retains its global merchant scope and repeats the resolved merchant id
 * as defence in depth. It reads only completed domains: subscription terms/invoices, branches,
 * memberships/invitations and the existing masked compensation summary. Revenue, performance,
 * daily reports and notification facts remain absent while their canonical runtime is behind
 * External Gate W; a named gate statement is returned instead of a misleading zero.
 */
final class MerchantOwnerDashboardReadModel
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MerchantCompensationSummaryReadModel $compensation,
    ) {}

    /** @return array<string, mixed> */
    public function read(Merchant $merchant): array
    {
        /** @var MerchantSubscription|null $subscription */
        $subscription = MerchantSubscription::query()
            ->where('merchant_id', $merchant->id)
            ->with(['plan', 'price'])
            ->latest('id')
            ->first();

        $branchCounts = MerchantBranch::query()
            ->where('merchant_id', $merchant->id)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $membershipCounts = MerchantUser::query()
            ->where('merchant_id', $merchant->id)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        /** @var MerchantProfile|null $profile */
        $profile = MerchantProfile::query()
            ->where('merchant_id', $merchant->id)
            ->first();

        $activeRoles = MerchantUser::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', MerchantUserStatus::Active->value)
            ->pluck('role')
            ->map(static fn (mixed $role): string => $role instanceof MerchantUserRole ? $role->value : (string) $role)
            ->unique()
            ->values();

        $invitedOwnerRoles = StaffInvitation::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('role', [MerchantUserRole::BranchManager->value, MerchantUserRole::Hr->value])
            ->whereIn('status', [StaffInvitationStatus::Pending->value, StaffInvitationStatus::Accepted->value])
            ->pluck('role')
            ->map(static fn (mixed $role): string => $role instanceof MerchantUserRole ? $role->value : (string) $role)
            ->unique()
            ->values();

        $hasLogo = UploadedFile::query()
            ->where('merchant_id', $merchant->id)
            ->where('purpose', FilePurpose::MerchantLogo->value)
            ->where('scan_status', FileScanStatus::Clean->value)
            ->where('lifecycle_status', FileLifecycleStatus::Available->value)
            ->exists();

        $branchTotal = (int) $branchCounts->sum();
        $branchLimit = $subscription?->plan
            ?->entitlements()
            ->where('entitlement_key', 'merchant.branch.count')
            ->where('enabled', true)
            ->value('limit_int');

        /** @var SubscriptionInvoice|null $nextInvoice */
        $nextInvoice = SubscriptionInvoice::query()
            ->where('merchant_id', $merchant->id)
            ->whereNotIn('status', [
                SubscriptionInvoiceStatus::Draft->value,
                SubscriptionInvoiceStatus::Paid->value,
                SubscriptionInvoiceStatus::Void->value,
            ])
            ->orderByRaw('due_at asc nulls last')
            ->latest('id')
            ->first();

        $outstanding = SubscriptionInvoice::query()
            ->where('merchant_id', $merchant->id)
            ->where('balance_minor', '>', 0)
            ->where('status', '!=', SubscriptionInvoiceStatus::Void->value)
            ->toBase()
            ->selectRaw('currency, sum(balance_minor) as amount_minor')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(static fn (object $row): array => [
                'currency' => (string) $row->currency,
                'amount_minor' => (int) $row->amount_minor,
            ])->values()->all();

        $subscriptionData = null;
        $plan = $subscription?->plan;
        $price = $subscription?->price;
        if ($subscription !== null && $plan !== null && $price !== null) {
            $subscriptionData = [
                'status' => $subscription->status->value,
                'billing_status' => $merchant->billing_status->value,
                'billing_read_only' => $merchant->billingBlocksMutations(),
                'plan_name' => $plan->name,
                'billing_interval' => $subscription->billing_interval->value,
                'amount_minor' => $price->amount_minor,
                'currency' => $price->currency,
                'trial_ends_at' => $subscription->trial_ends_at->toIso8601String(),
                'current_period_end' => $subscription->current_period_end->toDateString(),
                'scheduled_change' => $subscription->pendingScheduledChange() !== null,
            ];
        }

        return [
            'subscription' => $subscriptionData,
            'billing' => [
                'next_invoice' => $nextInvoice === null ? null : [
                    'id' => $nextInvoice->ulid,
                    'invoice_number' => $nextInvoice->invoice_number,
                    'status' => $nextInvoice->status->value,
                    'balance_minor' => $nextInvoice->balance_minor,
                    'currency' => $nextInvoice->currency,
                    'due_at' => $nextInvoice->due_at?->toIso8601String(),
                ],
                'outstanding_by_currency' => $outstanding,
                'payment_runtime' => [
                    'available' => false,
                    'reason' => 'External Gate W — Wallet by Citrus collections readiness',
                ],
            ],
            'branches' => [
                'total' => $branchTotal,
                'active' => (int) ($branchCounts[BranchStatus::Active->value] ?? 0),
                'suspended' => (int) ($branchCounts[BranchStatus::Suspended->value] ?? 0),
                'archived' => (int) ($branchCounts[BranchStatus::Archived->value] ?? 0),
                'limit' => $branchLimit === null ? null : (int) $branchLimit,
                'remaining_capacity' => $branchLimit === null ? null : max(0, (int) $branchLimit - $branchTotal),
            ],
            'staff' => [
                'active' => (int) ($membershipCounts[MerchantUserStatus::Active->value] ?? 0),
                'invited' => (int) ($membershipCounts[MerchantUserStatus::Invited->value] ?? 0),
                'suspended' => (int) ($membershipCounts[MerchantUserStatus::Suspended->value] ?? 0),
                'deactivated' => (int) ($membershipCounts[MerchantUserStatus::Deactivated->value] ?? 0),
                'pending_owner_invitations' => StaffInvitation::query()
                    ->where('merchant_id', $merchant->id)
                    ->where('status', StaffInvitationStatus::Pending->value)
                    ->count(),
            ],
            'get_started' => [
                'setup_complete' => true,
                'subscription_selected' => $subscription !== null,
                'profile_complete' => $profile !== null
                    && filled($profile->business_category)
                    && filled($profile->contact_phone),
                'logo_uploaded' => $hasLogo,
                'billing_phone_confirmed' => $profile !== null && filled($profile->contact_phone),
                'first_branch_created' => $branchTotal > 0,
                'initial_team_invited' => collect([MerchantUserRole::BranchManager->value, MerchantUserRole::Hr->value])
                    ->every(static fn (string $role): bool => $activeRoles->contains($role) || $invitedOwnerRoles->contains($role)),
                'initial_team_active' => collect([MerchantUserRole::BranchManager->value, MerchantUserRole::Hr->value])
                    ->every(static fn (string $role): bool => $activeRoles->contains($role)),
                'operational_roles_active' => collect([
                    MerchantUserRole::BranchManager->value,
                    MerchantUserRole::Hr->value,
                    MerchantUserRole::Finance->value,
                    MerchantUserRole::FrontOffice->value,
                ])->every(static fn (string $role): bool => $activeRoles->contains($role)),
                'daily_reports' => [
                    'available' => false,
                    'reason' => 'External Gate W — Wallet by Citrus collections readiness',
                ],
            ],
            'compensation' => $this->context->can('merchant.compensation_summary.view')
                ? $this->compensation->summary($merchant->id)
                : null,
            'reporting' => [
                'available' => false,
                'reason' => 'External Gate W — Wallet by Citrus collections readiness',
                'omitted_metrics' => ['validated_revenue', 'branch_performance', 'staff_performance', 'daily_reports'],
            ],
        ];
    }
}
