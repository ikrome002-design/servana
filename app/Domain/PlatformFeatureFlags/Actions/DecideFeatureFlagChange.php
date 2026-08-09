<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagChangeRequestStatus;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Domain\PlatformFeatureFlags\Exceptions\PlatformFeatureFlagException;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagChangeRequest;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagHistory;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagTarget;
use App\Domain\PlatformFeatureFlags\Services\PlatformFeatureFlagStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Approve, reject or cancel a feature-flag change request (COR-UI08-001 §12.3; Phase UI-08).
 *
 * MAKER/CHECKER. Approval requires a DIFFERENT administrator than the requester. The service refuses
 * it, and `platform_feature_flag_change_requests_maker_checker_check` refuses it again at the
 * database, so a self-approved change cannot exist as a row even if this layer were bypassed.
 *
 * Approval APPLIES the configuration in the same transaction: the flag is written, its version
 * bumped, the approved hash recorded, targets replaced, and a history row appended. Either all of
 * that happens or none of it does — a flag left half-applied would be a rollout nobody could reason
 * about.
 */
final class DecideFeatureFlagChange
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformFeatureFlagStateMachine $stateMachine,
    ) {}

    public function approve(PlatformFeatureFlagChangeRequest $request, User $actor): PlatformFeatureFlagChangeRequest
    {
        return DB::transaction(function () use ($request, $actor): PlatformFeatureFlagChangeRequest {
            $locked = $this->lockPending($request);

            if ($locked->requested_by_user_id === $actor->id) {
                throw PlatformFeatureFlagException::selfApprovalForbidden();
            }

            $flag = $locked->flag()->with('targets')->lockForUpdate()->firstOrFail();
            $before = $flag->configuration();

            /** @var array<string, mixed> $proposed */
            $proposed = $locked->proposed_configuration;
            $targetState = PlatformFeatureFlagState::from((string) $proposed['state']);

            $this->stateMachine->assertCanTransition($flag->state, $targetState);

            $flag->forceFill([
                'state' => $targetState,
                'rollout_basis_points' => (int) $proposed['rollout_basis_points'],
                'effective_from' => $proposed['effective_from'] === null
                    ? null
                    : CarbonImmutable::parse((string) $proposed['effective_from']),
                'effective_to' => $proposed['effective_to'] === null
                    ? null
                    : CarbonImmutable::parse((string) $proposed['effective_to']),
                'version' => $flag->version + 1,
                'approved_configuration_hash' => $locked->proposed_configuration_hash,
                'applied_change_request_id' => $locked->id,
                'updated_by_user_id' => $actor->id,
            ])->save();

            // Targets are replaced wholesale: the approved set IS the set.
            $flag->targets()->delete();

            foreach ((array) ($proposed['targets'] ?? []) as $target) {
                [$type, $value] = explode(':', (string) $target, 2);

                PlatformFeatureFlagTarget::query()->create([
                    'feature_flag_id' => $flag->id,
                    'target_type' => $type,
                    'target_value' => $value,
                    'created_by_user_id' => $actor->id,
                ]);
            }

            $locked->forceFill([
                'status' => PlatformFeatureFlagChangeRequestStatus::Applied,
                'approved_by_user_id' => $actor->id,
                'decided_at' => now(),
                'applied_at' => now(),
            ])->save();

            $correlation = (string) Str::ulid();

            foreach (['approved', 'applied'] as $action) {
                PlatformFeatureFlagHistory::query()->create([
                    'feature_flag_id' => $flag->id,
                    'change_request_id' => $locked->id,
                    'action' => $action,
                    'before_configuration' => $before,
                    'after_configuration' => $flag->load('targets')->configuration(),
                    'before_hash' => PlatformFeatureFlagChangeRequest::hashConfiguration($before),
                    'after_hash' => $locked->proposed_configuration_hash,
                    'actor_user_id' => $actor->id,
                    'reason' => $locked->reason,
                    'correlation_id' => $correlation,
                ]);
            }

            $this->audit->record(AuditEvent::PlatformFeatureFlagChangeApproved, $actor, null, null, $locked, [
                'flag_key' => $flag->flag_key,
                'request_id' => $locked->ulid,
                'approved_hash' => $locked->proposed_configuration_hash,
            ]);

            $this->audit->record(AuditEvent::PlatformFeatureFlagApplied, $actor, null, null, $flag, [
                'flag_key' => $flag->flag_key,
                'environment' => $flag->environment,
                'state' => $flag->state->value,
                'version' => $flag->version,
            ]);

            return $locked->refresh();
        });
    }

    public function reject(PlatformFeatureFlagChangeRequest $request, string $decisionNote, User $actor): PlatformFeatureFlagChangeRequest
    {
        return DB::transaction(function () use ($request, $decisionNote, $actor): PlatformFeatureFlagChangeRequest {
            $locked = $this->lockPending($request);

            if ($locked->requested_by_user_id === $actor->id) {
                throw PlatformFeatureFlagException::selfApprovalForbidden();
            }

            $locked->forceFill([
                'status' => PlatformFeatureFlagChangeRequestStatus::Rejected,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
            ])->save();

            $this->appendHistory($locked, 'rejected', $actor, $decisionNote);

            $this->audit->record(AuditEvent::PlatformFeatureFlagChangeRejected, $actor, null, null, $locked, [
                'request_id' => $locked->ulid,
                'decision_note' => $decisionNote,
            ]);

            return $locked->refresh();
        });
    }

    /** Only the requester may withdraw their own proposal. */
    public function cancel(PlatformFeatureFlagChangeRequest $request, User $actor): PlatformFeatureFlagChangeRequest
    {
        return DB::transaction(function () use ($request, $actor): PlatformFeatureFlagChangeRequest {
            $locked = $this->lockPending($request);

            if ($locked->requested_by_user_id !== $actor->id) {
                throw PlatformFeatureFlagException::requesterOnly();
            }

            $locked->forceFill([
                'status' => PlatformFeatureFlagChangeRequestStatus::Cancelled,
                'decided_at' => now(),
            ])->save();

            $this->appendHistory($locked, 'cancelled', $actor, $locked->reason);

            $this->audit->record(AuditEvent::PlatformFeatureFlagChangeCancelled, $actor, null, null, $locked, [
                'request_id' => $locked->ulid,
            ]);

            return $locked->refresh();
        });
    }

    private function lockPending(PlatformFeatureFlagChangeRequest $request): PlatformFeatureFlagChangeRequest
    {
        /** @var PlatformFeatureFlagChangeRequest $locked */
        $locked = PlatformFeatureFlagChangeRequest::query()
            ->whereKey($request->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== PlatformFeatureFlagChangeRequestStatus::Pending) {
            throw PlatformFeatureFlagException::invalidRequestTransition(
                $locked->status,
                PlatformFeatureFlagChangeRequestStatus::Approved,
            );
        }

        return $locked;
    }

    private function appendHistory(
        PlatformFeatureFlagChangeRequest $request,
        string $action,
        User $actor,
        ?string $reason,
    ): void {
        $flag = $request->flag()->with('targets')->firstOrFail();

        PlatformFeatureFlagHistory::query()->create([
            'feature_flag_id' => $flag->id,
            'change_request_id' => $request->id,
            'action' => $action,
            'before_configuration' => $flag->configuration(),
            // A rejected or cancelled request changes nothing, so before and after are identical —
            // recorded explicitly rather than left null, so the trail shows the flag was untouched.
            'after_configuration' => $flag->configuration(),
            'before_hash' => PlatformFeatureFlagChangeRequest::hashConfiguration($flag->configuration()),
            'after_hash' => PlatformFeatureFlagChangeRequest::hashConfiguration($flag->configuration()),
            'actor_user_id' => $actor->id,
            'reason' => $reason,
            'correlation_id' => (string) Str::ulid(),
        ]);
    }
}
