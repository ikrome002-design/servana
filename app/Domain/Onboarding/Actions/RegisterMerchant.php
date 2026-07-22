<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Integrations\ReferEarn\Actions\CaptureReferralSnapshot;
use App\Domain\Integrations\ReferEarn\Actions\EnqueueProductEvent;
use App\Domain\Integrations\ReferEarn\Data\ReferralCaptureData;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Integrations\ReferEarn\Jobs\ValidateReferralCodeJob;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use App\Domain\Merchants\Models\MerchantStatusHistory;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Merchant Administrator self-registration (Scope §3.1/§3.2, Plan §27 Phase 6).
 *
 * Creates, in a single transaction:
 *   - the registering user (active, no password — Magic Link only),
 *   - the merchant tenant (pending_setup),
 *   - a shell merchant profile (1:1 always exists),
 *   - the owner membership (merchant_admin / active),
 *   - a status-history row (→ pending_setup).
 *
 * The registering user IS the Merchant Owner / Merchant Administrator (they are
 * the same account — never split, Scope §3.2). There is no Super Admin approval,
 * no KYC, and no platform creation path.
 *
 * Returns null when the email already belongs to a user: the caller responds
 * uniformly either way so registration cannot be used to enumerate accounts
 * (Plan §9.1 enumeration rule applied to onboarding).
 */
final class RegisterMerchant
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CaptureReferralSnapshot $captureReferral,
        private readonly EnqueueProductEvent $enqueueProductEvent,
    ) {}

    public function handle(
        string $ownerName,
        string $email,
        string $businessName,
        ?ReferralCaptureData $referral = null,
    ): ?Merchant {
        $email = Str::lower(trim($email));

        // One identity per email. An existing email never creates a second
        // merchant or a duplicate owner — and the response does not reveal it.
        if (User::query()->where('email', $email)->exists()) {
            return null;
        }

        $snapshotId = null;

        $merchant = DB::transaction(function () use ($ownerName, $email, $businessName, $referral, &$snapshotId): Merchant {
            $user = new User;
            $user->name = $ownerName;
            $user->email = $email;
            $user->status = User::STATUS_ACTIVE;
            $user->save();

            $merchant = new Merchant;
            $merchant->name = $businessName;
            $merchant->slug = $this->uniqueSlug($businessName);
            $merchant->status = MerchantStatus::PendingSetup;
            $merchant->created_by = $user->id;
            $merchant->save();

            MerchantProfile::query()->create([
                'merchant_id' => $merchant->id,
                'contact_email' => $email,
            ]);

            $membership = MerchantUser::query()->create([
                'merchant_id' => $merchant->id,
                'user_id' => $user->id,
                'role' => MerchantUserRole::MerchantAdmin,
                'status' => MerchantUserStatus::Active,
                'activated_at' => now(),
            ]);

            MerchantStatusHistory::query()->create([
                'merchant_id' => $merchant->id,
                'from_status' => null,
                'to_status' => MerchantStatus::PendingSetup->value,
                'reason' => 'merchant_self_registration',
                'changed_by' => $user->id,
            ]);

            // Audit the founding owner membership (Plan §70). The new owner is the
            // actor; merchant-level event (no branch yet).
            $this->audit->record(
                AuditEvent::MembershipCreated,
                $user,
                $merchant->id,
                null,
                $membership,
                ['target_membership' => $membership->ulid, 'target_role' => $membership->role->value, 'via' => 'self_registration'],
            );

            // ── Phase 21R-A additive extension (Plan §58A.1, §58B.1; ADR-013) ────────────────
            // Capture the referral snapshot INSIDE this transaction so the evidence and the
            // registration commit or roll back together, then enqueue the two registration facts
            // through the same outbox rule. `EnqueueProductEvent` applies the §58B.1 emission-scope
            // gate itself, so an unreferred merchant — and a malformed code — emit nothing at all.
            //
            // Nothing here calls Citrus R&E: registration is never blocked or failed because R&E is
            // unavailable (Plan A-19, §58B.5 R-03). Validation is queued after commit, below.
            $snapshot = $this->captureReferral->handle($merchant, $referral);
            $snapshotId = $snapshot?->snapshot_status->permitsEventEmission() === true ? $snapshot->id : null;

            $this->enqueueProductEvent->handle(ReOutboundEventType::MerchantRegistrationStarted, $merchant);
            // Emitted once the founding merchant_admin membership above exists — same transaction,
            // so the fact and its event are inseparable.
            $this->enqueueProductEvent->handle(ReOutboundEventType::MerchantAdminCreated, $merchant);

            return $merchant;
        });

        // AFTER COMMIT. A queued job must never observe a half-written snapshot, and a queue outage
        // must never roll back a merchant registration.
        if ($snapshotId !== null) {
            ValidateReferralCodeJob::dispatch($snapshotId);
        }

        return $merchant;
    }

    /** Lowercased, unique slug derived from the business name. */
    private function uniqueSlug(string $businessName): string
    {
        $base = Str::slug($businessName);

        if ($base === '') {
            $base = 'merchant';
        }

        $slug = $base;

        // Append a short random suffix on collision (cheap, avoids a race-prone
        // counter). The unique index is the real backstop.
        while (Merchant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
