<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Services;

use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Support\PlatformFeatureFlagDecision;
use Carbon\CarbonImmutable;

/**
 * The single server-side flag evaluation authority (COR-UI08-001 §12.4; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-feature-flag.md.
 *
 * A FLAG MAY TURN AN OTHERWISE-AUTHORIZED CAPABILITY OFF. IT MAY NEVER TURN AN UNAUTHORIZED
 * CAPABILITY ON. This class returns a boolean about ROLLOUT only; permission, entitlement, billing
 * state and account context are evaluated independently, elsewhere, and are never consulted by, or
 * replaced by, anything here. There is deliberately no way to ask this class "may this user do X?".
 *
 * FAIL CLOSED, IN THIS ORDER — the first failure denies and says why:
 *
 *   1. code allowlist    an unknown key is not a flag at all
 *   2. environment       the definition must permit this environment
 *   3. external gate     a closed canonical gate (Gate W) denies outright
 *   4. flag state        only `active` can ever allow
 *   5. effective dates   outside [effective_from, effective_to) denies
 *   6. target            when targets exist, the subject must match one
 *   7. rollout           a deterministic bucket must fall under the basis points
 *
 * NOTHING HERE CAN OPEN A GATE. `externalGateIsOpen()` has no setter, no column and no endpoint
 * behind it: Gate W is an evidence-based launch gate, and the only way this method returns true is
 * a code change accompanied by that evidence.
 *
 * NEVER EVALUATED IN THE BROWSER. There is no client counterpart; the frontend receives resulting
 * booleans as UX hints and every protected request re-evaluates server-side.
 */
final class PlatformFeatureFlagEvaluator
{
    public function __construct(private readonly PlatformFeatureFlagCatalogue $catalogue) {}

    /** Convenience boolean for callers that do not need the reason. */
    public function allows(string $flagKey, ?string $subjectUlid = null, ?CarbonImmutable $at = null): bool
    {
        return $this->decide($flagKey, $subjectUlid, $at)->allowed;
    }

    public function decide(string $flagKey, ?string $subjectUlid = null, ?CarbonImmutable $at = null): PlatformFeatureFlagDecision
    {
        $at ??= CarbonImmutable::now();
        $environment = (string) app()->environment();

        // 1. The code allowlist. An unknown key is not a flag; it is a typo or an attack.
        $definition = $this->catalogue->definition($flagKey);

        if ($definition === null) {
            return PlatformFeatureFlagDecision::deny('unknown_flag_key');
        }

        // 2. Environment support.
        if (! $definition->supportsEnvironment($environment)) {
            return PlatformFeatureFlagDecision::deny('environment_not_supported');
        }

        // 3. The external gate. A flag can never make Gate-W functionality available.
        if ($definition->externalGate !== null && ! $this->externalGateIsOpen($definition->externalGate)) {
            return PlatformFeatureFlagDecision::deny('external_gate_closed');
        }

        $flag = PlatformFeatureFlag::query()
            ->where('flag_key', $flagKey)
            ->where('environment', $environment)
            ->with('targets')
            ->first();

        // No row for this environment is the default state, and the default is OFF.
        if ($flag === null) {
            return PlatformFeatureFlagDecision::deny('no_state_for_environment');
        }

        // 4. State.
        if (! $flag->state->canAllow()) {
            return PlatformFeatureFlagDecision::deny('state_'.$flag->state->value);
        }

        // 5. Effective dates.
        if ($flag->effective_from === null || $flag->effective_from->greaterThan($at)) {
            return PlatformFeatureFlagDecision::deny('not_yet_effective');
        }

        if ($flag->effective_to !== null && ! $flag->effective_to->greaterThan($at)) {
            return PlatformFeatureFlagDecision::deny('no_longer_effective');
        }

        // 6. Targets. When any exist, the subject must match one — an untargeted subject is denied
        // rather than quietly included.
        if ($flag->targets->isNotEmpty()) {
            if ($subjectUlid === null) {
                return PlatformFeatureFlagDecision::deny('no_subject_for_targeted_flag');
            }

            $matches = $flag->targets->contains(
                static fn ($target): bool => $target->target_value === $subjectUlid,
            );

            if (! $matches) {
                return PlatformFeatureFlagDecision::deny('subject_not_targeted');
            }
        }

        // 7. Rollout. Integer basis points, deterministic bucket.
        if ($flag->rollout_basis_points <= 0) {
            return PlatformFeatureFlagDecision::deny('rollout_zero');
        }

        if ($flag->rollout_basis_points >= 10_000) {
            return PlatformFeatureFlagDecision::allow('full_rollout');
        }

        if ($subjectUlid === null) {
            // A partial rollout with no subject cannot be bucketed, so it denies rather than
            // guessing — a coin flip here would make the same request allow and deny at random.
            return PlatformFeatureFlagDecision::deny('no_subject_for_partial_rollout');
        }

        return $this->bucket($flagKey, $subjectUlid) < $flag->rollout_basis_points
            ? PlatformFeatureFlagDecision::allow('within_rollout')
            : PlatformFeatureFlagDecision::deny('outside_rollout');
    }

    /**
     * A stable bucket in [0, 10000). The same subject always lands in the same bucket for the same
     * flag, so a rollout only ever WIDENS and there is no randomness to reproduce.
     */
    public function bucket(string $flagKey, string $subjectUlid): int
    {
        return (int) (crc32($flagKey.':'.$subjectUlid) % 10_000);
    }

    /**
     * External gates are evidence-based launch gates, not configuration.
     *
     * There is no setter, no column, no endpoint and no flag state that can change this answer.
     * Gate W is closed, and it stays closed until a separate, evidence-backed decision opens it in
     * code — which is exactly why a feature flag cannot be used to smuggle Wallet or Refer & Earn
     * functionality into production ahead of that decision.
     */
    public function externalGateIsOpen(string $gate): bool
    {
        return false;
    }
}
