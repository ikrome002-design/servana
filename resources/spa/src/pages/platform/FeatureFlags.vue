<script setup lang="ts">
/**
 * Feature Flags — Super Administrator contract page §5.4.20 (Phase UI-08).
 *
 * Controls approved platform feature rollout under maker/checker (COR-UI08-001 §12).
 *
 * WHAT A FLAG IS, AND IS NOT. A flag is a RESTRICTIVE rollout control. It cannot grant: the
 * evaluator has no access to permissions, entitlements, billing state or account context, and an
 * active, fully rolled-out, correctly targeted flag still denies with `external_gate_closed`. This
 * page therefore never describes a flag as access, and never offers a flag as a way to reach a
 * gated capability.
 *
 * THE CATALOGUE IS TRUTHFULLY EMPTY. `config/platform-feature-flags.php` allowlists no flag today,
 * and the endpoint says so in `meta.catalogue_is_empty`. The page renders a real empty state that
 * explains why — it does not seed an example flag, fabricate a health metric, or invent a count to
 * look complete. There is deliberately no "create flag" route: a flag must be added to the code
 * allowlist first.
 *
 * MAKER/CHECKER IS STRUCTURAL. A database CHECK refuses a self-approved row even when policy,
 * controller and service are bypassed. This page surfaces the server's refusal rather than
 * restating the rule as a client-side guess.
 */
import { computed, onMounted, ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvEmptyState from '@/components/ui/SvEmptyState.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useCan } from '@/composables/useCan';
import { usePlatformFeatureFlagStore, type FeatureFlagRow } from '@/stores/platformFeatureFlagStore';

const store = usePlatformFeatureFlagStore();
const { can } = useCan();

const canView = computed(() => can('platform.settings.view'));
const canUpdate = computed(() => can('platform.settings.update'));

const catalogueIsEmpty = computed(() => store.catalogue?.catalogue_is_empty === true || store.flags.length === 0);

onMounted(() => {
  if (canView.value) void store.load();
});

function resolveMessage(error: unknown, fallback: string): string {
  return (error as { apiError?: { message?: string } }).apiError?.message ?? fallback;
}

/**
 * A flag key CONTAINS dots. Rendering it needs no special handling, but every path that carries it
 * does — the store encodes it, and nothing here splits it on `.`, which is exactly the trap that
 * made `config()->set('…flags.my.flag')` build a nested config path during Increment 6.
 */
const flagKeyOf = (row: FeatureFlagRow): string =>
  typeof row.definition === 'string' ? row.definition : String(row.state?.id ?? '');

// ---------------------------------------------------------------------------------------------
// Change request
// ---------------------------------------------------------------------------------------------

const changeOpen = ref(false);
const changeFlagKey = ref('');
const proposedConfiguration = ref('');
const impactStatement = ref('');
const rollbackPlan = ref('');
const healthCriterion = ref('');
const changeReason = ref('');
const changeSubmitting = ref(false);
const changeError = ref<string | null>(null);

function openChange(flagKey: string): void {
  changeFlagKey.value = flagKey;
  proposedConfiguration.value = '{\n  "state": "active",\n  "rollout_basis_points": 0\n}';
  impactStatement.value = '';
  rollbackPlan.value = '';
  healthCriterion.value = '';
  changeReason.value = '';
  changeError.value = null;
  changeOpen.value = true;
}

async function submitChange(): Promise<void> {
  if (changeSubmitting.value) return;
  changeSubmitting.value = true;
  changeError.value = null;

  let parsed: Record<string, unknown>;
  try {
    parsed = JSON.parse(proposedConfiguration.value) as Record<string, unknown>;
  } catch {
    changeError.value = 'The proposed configuration is not valid JSON.';
    changeSubmitting.value = false;
    return;
  }

  try {
    await store.requestChange(changeFlagKey.value, {
      proposed_configuration: parsed,
      impact_statement: impactStatement.value,
      rollback_plan: rollbackPlan.value,
      health_criterion: healthCriterion.value,
      reason: changeReason.value,
    });
    changeOpen.value = false;
  } catch (error) {
    changeError.value = resolveMessage(error, 'The server refused this change request.');
  } finally {
    changeSubmitting.value = false;
  }
}

// ---------------------------------------------------------------------------------------------
// Pause (kill switch)
// ---------------------------------------------------------------------------------------------

const pauseFlagKey = ref<string | null>(null);
const pauseReason = ref('');
const pauseSubmitting = ref(false);
const pauseError = ref<string | null>(null);

function openPause(flagKey: string): void {
  pauseFlagKey.value = flagKey;
  pauseReason.value = '';
  pauseError.value = null;
}

async function confirmPause(): Promise<void> {
  if (pauseSubmitting.value || pauseFlagKey.value === null) return;
  pauseSubmitting.value = true;
  pauseError.value = null;

  try {
    await store.pause(pauseFlagKey.value, pauseReason.value);
    pauseFlagKey.value = null;
  } catch (error) {
    pauseError.value = resolveMessage(error, 'Unable to pause this rollout.');
  } finally {
    pauseSubmitting.value = false;
  }
}

const stateTone = (state: string | null | undefined): 'success' | 'warning' | 'neutral' | 'info' => {
  if (state === 'active') return 'success';
  if (state === 'paused') return 'warning';
  if (state === 'scheduled') return 'info';
  return 'neutral';
};
</script>

<template>
  <div
    class="mx-auto w-full max-w-5xl"
    data-testid="feature-flags-screen"
  >
    <SvPageHeader
      title="Feature flags"
      eyebrow="Platform administration"
      description="Control the rollout of approved platform features. A flag can only restrict — it can never grant access, bypass an entitlement, change billing, or open an external gate."
    />

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <p
        class="mb-4 text-xs text-sv-text-muted"
        data-testid="flags-last-refreshed"
      >
        Last refreshed
        <SvDateTime :value="store.lastRefreshed" />
      </p>

      <SvAlert
        v-if="store.error"
        severity="error"
        title="We could not load the feature-flag catalogue"
        class="mb-6"
      >
        <p>{{ store.error }}</p>
        <SvButton
          variant="secondary"
          size="sm"
          class="mt-3"
          data-testid="flags-retry"
          @click="store.load()"
        >
          Try again
        </SvButton>
      </SvAlert>

      <SvSkeleton
        v-else-if="store.loading"
        shape="text"
        :lines="4"
        label="Loading feature flags"
      />

      <!--
        The truthful empty state. The catalogue is empty because no flag is allowlisted in code —
        that is the real reason, and it is stated. No example flag is seeded, no health figure is
        fabricated, and no "create flag" control is offered, because creating one requires a code
        change first.
      -->
      <template v-else-if="catalogueIsEmpty">
        <SvEmptyState
          title="No feature flag is currently allowlisted"
          description="A flag exists only after it is added to the platform's code allowlist. Nothing is scheduled, active or paused, so there is no rollout to control here yet."
          data-testid="flags-empty"
        />
        <div
          v-if="store.catalogue"
          class="mx-auto mt-4 max-w-sv-readable rounded-card border border-sv-border bg-sv-surface-raised p-4 text-sm"
          data-testid="flags-catalogue-meta"
        >
          <dl class="space-y-2">
            <div class="flex justify-between gap-4">
              <dt class="text-sv-text-muted">
                Environment
              </dt>
              <dd class="text-sv-text">
                {{ store.catalogue.environment }}
              </dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-sv-text-muted">
                Allowlisted flags
              </dt>
              <dd class="sv-numeric text-sv-text">
                {{ store.catalogue.catalogue_size }}
              </dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-sv-text-muted">
                Catalogue source
              </dt>
              <dd class="min-w-0 break-words text-right text-sv-text">
                {{ store.catalogue.catalogue_source }}
              </dd>
            </div>
          </dl>
          <p class="mt-3 text-xs text-sv-text-muted">
            {{ store.catalogue.note }}
          </p>
        </div>
      </template>

      <!-- Populated catalogue -------------------------------------------------------------- -->
      <ul
        v-else
        class="space-y-4"
        data-testid="flags-list"
      >
        <li
          v-for="row in store.flags"
          :key="flagKeyOf(row)"
          class="rounded-card border border-sv-border bg-sv-surface-raised p-4"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="break-words font-medium text-sv-text">
                {{ flagKeyOf(row) }}
              </p>
              <p class="mt-1 text-xs text-sv-text-muted">
                Environment {{ row.state?.environment ?? 'not configured' }} · rollout
                {{ row.state?.rollout_basis_points ?? 0 }} basis points
              </p>
            </div>
            <SvStatusBadge
              :label="String(row.effective_state ?? row.state?.state ?? 'inactive')"
              :tone="stateTone(String(row.effective_state ?? row.state?.state ?? ''))"
              sr-prefix="Rollout state:"
            />
          </div>

          <p class="mt-3 text-xs text-sv-text-muted">
            A flag never grants access. Permissions, entitlements, billing state and external gates
            are evaluated independently and always win.
          </p>

          <div
            v-if="canUpdate"
            class="mt-3 flex flex-wrap gap-2"
          >
            <SvButton
              variant="secondary"
              size="sm"
              :data-testid="`flags-history-${flagKeyOf(row)}`"
              @click="store.openFlag(flagKeyOf(row))"
            >
              View history
            </SvButton>
            <SvButton
              variant="secondary"
              size="sm"
              :data-testid="`flags-change-${flagKeyOf(row)}`"
              @click="openChange(flagKeyOf(row))"
            >
              Request a change
            </SvButton>
            <SvButton
              variant="destructive"
              size="sm"
              :data-testid="`flags-pause-${flagKeyOf(row)}`"
              @click="openPause(flagKeyOf(row))"
            >
              Pause rollout
            </SvButton>
          </div>
        </li>
      </ul>

      <!-- History -------------------------------------------------------------------------- -->
      <section
        v-if="store.history"
        aria-labelledby="flags-history-heading"
        class="mt-8"
      >
        <h2
          id="flags-history-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Change history
        </h2>
        <p class="mb-3 text-sm text-sv-text-muted">
          Append-only. A history row is never updated or deleted.
        </p>
        <ul
          v-if="Array.isArray(store.history)"
          class="space-y-2"
          data-testid="flags-history"
        >
          <li
            v-for="entry in store.history"
            :key="String(entry.id)"
            class="rounded-card border border-sv-border p-3 text-sm"
          >
            <p class="font-medium text-sv-text">
              {{ entry.action }}
            </p>
            <p class="text-xs text-sv-text-muted">
              <SvDateTime :value="entry.created_at" /> · {{ entry.reason ?? 'No reason recorded' }}
            </p>
          </li>
        </ul>
      </section>
    </template>

    <!-- Change request ---------------------------------------------------------------------- -->
    <SvDialog
      :open="changeOpen"
      title="Request a feature-flag change"
      description="A change is proposed, not applied. A different platform administrator must approve it — the database refuses a self-approved change even if every other check is bypassed."
      size="lg"
      persistent
      @close="changeOpen = false"
    >
      <div class="space-y-4">
        <SvTextArea
          id="flags-configuration"
          v-model="proposedConfiguration"
          label="Proposed configuration (JSON)"
          :rows="6"
          required
        />
        <SvTextArea
          id="flags-impact"
          v-model="impactStatement"
          label="Impact"
          :rows="3"
          help="Plain language: which screens and APIs change, and for whom."
          required
        />
        <SvTextArea
          id="flags-rollback"
          v-model="rollbackPlan"
          label="Rollback plan"
          :rows="3"
          help="How this is reversed if the health criterion is not met."
          required
        />
        <SvTextArea
          id="flags-health"
          v-model="healthCriterion"
          label="Health criterion"
          :rows="2"
          help="The measurable condition that says this rollout is working."
          required
        />
        <SvTextArea
          id="flags-reason"
          v-model="changeReason"
          label="Reason"
          :rows="2"
          required
        />

        <SvAlert
          v-if="changeError"
          severity="error"
          data-testid="flags-change-error"
        >
          <p>{{ changeError }}</p>
        </SvAlert>

        <p class="text-xs text-sv-text-muted">
          This change requires multi-factor authentication and a fresh step-up. You cannot approve
          your own request.
        </p>
      </div>

      <template #footer>
        <SvButton
          variant="ghost"
          @click="changeOpen = false"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="primary"
          :loading="changeSubmitting"
          loading-label="Submitting"
          data-testid="flags-change-submit"
          @click="submitChange"
        >
          Submit for approval
        </SvButton>
      </template>
    </SvDialog>

    <!-- Pause ------------------------------------------------------------------------------- -->
    <SvDialog
      :open="pauseFlagKey !== null"
      title="Pause this rollout?"
      description="Pausing takes effect immediately and does not require a second approver — stopping a rollout is always allowed to be faster than starting one."
      persistent
      @close="pauseFlagKey = null"
    >
      <div class="space-y-4">
        <SvTextArea
          id="flags-pause-reason"
          v-model="pauseReason"
          label="Reason"
          :rows="3"
          help="Recorded on the append-only history."
          required
        />
        <SvAlert
          v-if="pauseError"
          severity="error"
          data-testid="flags-pause-error"
        >
          <p>{{ pauseError }}</p>
        </SvAlert>
      </div>

      <template #footer>
        <SvButton
          variant="ghost"
          @click="pauseFlagKey = null"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="destructive"
          :loading="pauseSubmitting"
          loading-label="Pausing"
          data-testid="flags-pause-submit"
          @click="confirmPause"
        >
          Pause rollout
        </SvButton>
      </template>
    </SvDialog>
  </div>
</template>
