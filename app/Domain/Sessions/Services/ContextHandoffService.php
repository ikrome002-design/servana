<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Services;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Services\LoginEligibilityService;
use App\Domain\Auth\Support\AuthAuditLogger;
use App\Domain\Sessions\Enums\HandoffRejectionReason;
use App\Domain\Sessions\Models\AccountContextHandoff;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Models\SessionFamily;
use App\Domain\Sessions\Support\AccountContext;
use App\Domain\Sessions\Support\HandoffConsumeResult;
use App\Http\Hosts\AccountHostUrlGenerator;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mints and consumes the single-use credential that moves a user between account hosts
 * (Phase UI-03; ADR-018 steps 3–10; UI/UX plan §5.3).
 *
 * The token is a BEARER CREDENTIAL and is treated as one: 64 random bytes, SHA-256 at rest,
 * 120-second life, consumed once under a row lock, bound to its exact target.
 *
 * THE SAFETY OF THE WHOLE FLOW IS STEP 8. Consuming does not "restore" anything: it re-reads the
 * user, the merchant, the membership, the role and the branch from the database and rebuilds the
 * target context from scratch. A role revoked one second after issuance therefore yields no target
 * session, and the source context's permissions are never carried anywhere — there is nowhere in
 * the row to put them.
 *
 * Every rejection is uniform to the caller (`null`). The reason is written to the audit trail so
 * an operator can tell a replay from an expiry; the browser learns nothing (UI/UX plan §5.4).
 */
final class ContextHandoffService
{
    /** Long enough for a cross-host redirect on a slow mobile connection; short enough to matter. */
    public const EXPIRY_SECONDS = 120;

    public function __construct(
        private readonly AccountContextResolver $contexts,
        private readonly LoginEligibilityService $eligibility,
        private readonly AccountHostUrlGenerator $urls,
        private readonly AuthAuditLogger $audit,
    ) {}

    /**
     * Mint a handoff for a target context and return the ABSOLUTE target URL.
     *
     * The URL host comes from the registry via {@see AccountHostUrlGenerator}, never from the
     * request — a poisoned `Host` header cannot steer where the user is sent.
     */
    public function issue(
        User $user,
        HostSession $sourceHostSession,
        AccountContext $target,
        ?string $redirectPath,
        ?string $ipAddress,
        ?string $userAgent,
    ): string {
        $rawToken = $this->generateRawToken();

        // A user may hold only one live handoff: minting a new one retires any earlier unconsumed
        // token, so a token abandoned mid-switch cannot be picked up later from a browser history
        // entry or a shared screen.
        AccountContextHandoff::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update([
                'invalidated_at' => now(),
                'invalidated_reason' => HandoffRejectionReason::Superseded->value,
                'updated_at' => now(),
            ]);

        AccountContextHandoff::query()->create([
            'token_hash' => $this->hash($rawToken),
            'user_id' => $user->id,
            'source_session_family_id' => $sourceHostSession->session_family_id,
            'source_host_session_id' => $sourceHostSession->id,
            'source_account_key' => $sourceHostSession->account_key,
            'target_account_key' => $target->accountKey,
            'target_host' => $target->targetHost,
            'environment' => $sourceHostSession->environment,
            'target_merchant_id' => $target->merchantId,
            'target_merchant_user_id' => $target->merchantUserId,
            'target_branch_id' => $target->branchId,
            'redirect_path' => $redirectPath,
            'expires_at' => Carbon::now()->addSeconds(self::EXPIRY_SECONDS),
            'ip_hash' => $ipAddress !== null ? hash('sha256', $ipAddress) : null,
            'user_agent_hash' => $userAgent !== null ? hash('sha256', $userAgent) : null,
        ]);

        $this->audit->record(AuditEvent::ContextHandoffIssued, $user->email, $target->accountKey, $user->ulid);

        return $this->urls->to(
            $target->accountKey,
            '/auth/switch?token='.$rawToken,
            $sourceHostSession->environment,
        );
    }

    /**
     * Atomically consume a handoff presented on the target host, or return null.
     *
     * The lock-then-verify-then-mark sequence runs in ONE transaction, so two simultaneous
     * consumers cannot both succeed: the second blocks on `FOR UPDATE`, then finds `consumed_at`
     * already set and is rejected as a replay.
     */
    public function consume(string $rawToken, string $accountKey, string $host, string $environment): ?HandoffConsumeResult
    {
        $hash = $this->hash($rawToken);

        /** @var array{0: ?HandoffConsumeResult, 1: ?HandoffRejectionReason, 2: ?User} $outcome */
        $outcome = DB::transaction(function () use ($hash, $accountKey, $host, $environment): array {
            /** @var AccountContextHandoff|null $handoff */
            $handoff = AccountContextHandoff::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($handoff === null) {
                return [null, null, null];
            }

            $user = User::query()->find($handoff->user_id);
            $rejection = $this->rejectionFor($handoff, $accountKey, $host, $environment, $user);

            if ($rejection !== null) {
                $this->invalidate($handoff, $rejection);

                return [null, $rejection, $user];
            }

            // Rebuild the target context from CURRENT database state (ADR-018 step 7). This is the
            // control that makes the switch safe; everything else is plumbing around it.
            /** @var User $user */
            $context = $this->contexts->findForUser($user, $handoff->target_account_key, $environment);

            if ($context === null || $context->merchantUserId !== $handoff->target_merchant_user_id) {
                // Either the context vanished, or it now resolves to a DIFFERENT membership than
                // the one the token was minted for. Both mean the target the user asked for no
                // longer exists as they asked for it.
                $this->invalidate($handoff, HandoffRejectionReason::TargetUnavailable);

                return [null, HandoffRejectionReason::TargetUnavailable, $user];
            }

            // Single-use, conditional: must affect exactly one row. Belt and braces alongside the
            // row lock — if the lock were ever lost to a driver change, this still holds.
            $marked = AccountContextHandoff::query()
                ->where('id', $handoff->id)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            if ($marked !== 1) {
                return [null, HandoffRejectionReason::Replayed, $user];
            }

            $redirect = $this->urls->safeRelativePath($handoff->redirect_path);

            return [
                new HandoffConsumeResult(
                    user: $user,
                    context: $context,
                    sourceFamily: $handoff->sourceFamily,
                    redirectPath: $redirect,
                ),
                null,
                $user,
            ];
        });

        [$result, $rejection, $user] = $outcome;

        if ($result !== null) {
            $this->audit->record(AuditEvent::ContextHandoffConsumed, $user?->email, $accountKey, $user?->ulid);

            return $result;
        }

        $this->audit->record(
            $rejection === HandoffRejectionReason::Replayed
                ? AuditEvent::ContextHandoffReplayRejected
                : AuditEvent::ContextHandoffRejected,
            $user?->email,
            $rejection === null ? 'unknown_token' : $rejection->value,
            $user?->ulid,
        );

        return null;
    }

    /**
     * Which binding failed, or null when every one holds.
     *
     * Ordered cheapest-first, but the ORDER IS NOT OBSERVABLE: the caller returns the same uniform
     * failure for every branch, and the reason exists only in the audit row.
     */
    private function rejectionFor(
        AccountContextHandoff $handoff,
        string $accountKey,
        string $host,
        string $environment,
        ?User $user,
    ): ?HandoffRejectionReason {
        if ($handoff->consumed_at !== null) {
            return HandoffRejectionReason::Replayed;
        }
        if ($handoff->invalidated_at !== null) {
            return $handoff->invalidated_reason ?? HandoffRejectionReason::Superseded;
        }
        if ($handoff->expires_at->isPast()) {
            return HandoffRejectionReason::Expired;
        }
        if ($handoff->environment !== $environment) {
            return HandoffRejectionReason::WrongEnvironment;
        }
        if ($handoff->target_host !== $host || $handoff->target_account_key !== $accountKey) {
            return HandoffRejectionReason::WrongHost;
        }
        if ($user === null || ! $this->eligibility->check($user->email)->eligible) {
            return HandoffRejectionReason::UserIneligible;
        }

        /** @var SessionFamily|null $family */
        $family = $handoff->sourceFamily;

        if ($family === null || $family->revoked_at !== null) {
            return HandoffRejectionReason::FamilyRevoked;
        }

        // The source session must still be live. Signing out of the source host between minting
        // and consuming must not leave a usable credential behind.
        $sourceSession = $handoff->sourceHostSession;

        if ($handoff->source_host_session_id !== null
            && ($sourceSession === null || $sourceSession->revoked_at !== null)) {
            return HandoffRejectionReason::SourceSessionRevoked;
        }

        return null;
    }

    private function invalidate(AccountContextHandoff $handoff, HandoffRejectionReason $reason): void
    {
        if ($handoff->consumed_at !== null || $handoff->invalidated_at !== null) {
            // Already terminal. The CHECK forbids being both consumed and invalidated, and a
            // second invalidation would overwrite the original, truthful reason.
            return;
        }

        $handoff->forceFill([
            'invalidated_at' => now(),
            'invalidated_reason' => $reason,
        ])->save();
    }

    /** Delete handoffs that are past their forensic retention window. */
    public function prune(int $retentionHours = 24): int
    {
        return AccountContextHandoff::query()
            ->where('expires_at', '<', Carbon::now()->subHours($retentionHours))
            ->delete();
    }

    public function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /** 64 cryptographically secure random bytes, base64url-encoded. */
    private function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }
}
